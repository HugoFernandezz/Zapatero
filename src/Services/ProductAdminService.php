<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ProductAdminRepository;
use DomainException;
use Psr\Http\Message\UploadedFileInterface;

/** Validación y persistencia del CRUD de productos (back-office). */
final class ProductAdminService
{
    private const IMAGE_DIR = '/assets/img/products';
    private const UPLOAD_PREFIX = 'product-';
    private const MAX_IMAGE_BYTES = 2_097_152; // 2 MB
    private const MAX_PRICE_CENTS = 999_999;   // 9.999,99 €
    private const MAX_STOCK = 9999;

    public function __construct(
        private readonly ProductAdminRepository $products,
        private readonly string $publicDir
    ) {}

    public function all(): array
    {
        return $this->products->all();
    }

    public function find(int $id): ?array
    {
        return $this->products->find($id);
    }

    /** @return array{collections: array, styles: array, sizes: list<int>} */
    public function formOptions(): array
    {
        return [
            'collections' => $this->products->collections(),
            'styles' => $this->products->styles(),
            'sizes' => ProductAdminRepository::SIZES,
        ];
    }

    /** Valores iniciales de un formulario de alta. */
    public function emptyForm(): array
    {
        return [
            'name' => '', 'slug' => '', 'collection_id' => '', 'style_id' => '', 'description' => '',
            'material' => '', 'price' => '', 'image' => null,
            'stock' => array_fill_keys(ProductAdminRepository::SIZES, '0'),
        ];
    }

    /** Convierte un producto guardado en los valores del formulario de edición. */
    public function formFromProduct(array $product): array
    {
        $stock = [];
        foreach (ProductAdminRepository::SIZES as $size) {
            $stock[$size] = (string) ($product['stock'][$size] ?? 0);
        }

        return [
            'name' => (string) $product['name'],
            'slug' => (string) $product['slug'],
            'collection_id' => (string) $product['collection_id'],
            'style_id' => (string) $product['style_id'],
            'description' => (string) $product['description'],
            'material' => (string) $product['material'],
            'price' => number_format(((int) $product['price_cents']) / 100, 2, ',', ''),
            'image' => $product['image'] !== null ? (string) $product['image'] : null,
            'stock' => $stock,
        ];
    }

    /**
     * Valida el formulario. Si todo es correcto devuelve en "clean" los datos listos para guardar.
     *
     * @return array{form: array, errors: array<string,string>, clean: ?array{data: array, stock: array<int,int>, image_bytes: ?string, image_ext: ?string}}
     */
    public function validate(array $input, ?UploadedFileInterface $upload, ?int $currentId, ?string $currentImage): array
    {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $slugInput = trim((string) ($input['slug'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $material = trim((string) ($input['material'] ?? ''));
        $priceInput = trim((string) ($input['price'] ?? ''));
        $collectionId = filter_var($input['collection_id'] ?? null, FILTER_VALIDATE_INT);
        $styleId = filter_var($input['style_id'] ?? null, FILTER_VALIDATE_INT);

        if ($name === '') {
            $errors['name'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'El nombre es demasiado largo (máximo 120 caracteres).';
        }

        $slug = $slugInput !== '' ? strtolower($slugInput) : self::slugify($name);
        if ($slug === '' || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 120) {
            $errors['slug'] = 'El slug solo admite minúsculas, números y guiones (por ejemplo: mi-zapatilla).';
        } elseif ($this->products->slugTaken($slug, $currentId)) {
            $errors['slug'] = 'Ya existe otro producto con ese slug.';
        } elseif ($currentId === null && $this->products->skuTaken(ProductAdminRepository::sku($slug, 36))) {
            $errors['slug'] = 'Ese slug genera un SKU que ya existe. Cámbialo ligeramente.';
        }

        if (!is_int($collectionId) || !$this->products->collectionExists($collectionId)) {
            $errors['collection_id'] = 'Selecciona una colección.';
        }
        if (!is_int($styleId) || !$this->products->styleExists($styleId)) {
            $errors['style_id'] = 'Selecciona un estilo.';
        }

        if ($description === '') {
            $errors['description'] = 'La descripción es obligatoria.';
        } elseif (mb_strlen($description) > 1000) {
            $errors['description'] = 'La descripción es demasiado larga (máximo 1000 caracteres).';
        }
        if ($material === '') {
            $errors['material'] = 'El material es obligatorio.';
        } elseif (mb_strlen($material) > 300) {
            $errors['material'] = 'El material es demasiado largo (máximo 300 caracteres).';
        }

        $priceCents = self::parsePrice($priceInput);
        if ($priceCents === null || $priceCents < 1 || $priceCents > self::MAX_PRICE_CENTS) {
            $errors['price'] = 'Introduce un precio válido en euros, por ejemplo 64,90.';
        }

        $stock = [];
        $stockInput = is_array($input['stock'] ?? null) ? $input['stock'] : [];
        $formStock = [];
        foreach (ProductAdminRepository::SIZES as $size) {
            $raw = trim((string) ($stockInput[$size] ?? '0'));
            $formStock[$size] = $raw;
            $value = filter_var($raw === '' ? '0' : $raw, FILTER_VALIDATE_INT);
            if (!is_int($value) || $value < 0 || $value > self::MAX_STOCK) {
                $errors['stock'] = 'El stock de cada talla debe ser un número entero entre 0 y ' . self::MAX_STOCK . '.';
                continue;
            }
            $stock[$size] = $value;
        }

        [$imageBytes, $imageExt, $imageError] = $this->readUpload($upload);
        if ($imageError !== null) {
            $errors['image'] = $imageError;
        } elseif ($imageBytes === null && ($currentImage === null || $currentImage === '')) {
            $errors['image'] = 'Sube una imagen del producto (JPG, PNG o WebP).';
        }

        $form = [
            'name' => $name, 'slug' => $slugInput, 'collection_id' => (string) ($input['collection_id'] ?? ''),
            'style_id' => (string) ($input['style_id'] ?? ''), 'description' => $description, 'material' => $material,
            'price' => $priceInput, 'image' => $currentImage, 'stock' => $formStock,
        ];

        if ($errors !== []) {
            return ['form' => $form, 'errors' => $errors, 'clean' => null];
        }

        return [
            'form' => $form,
            'errors' => [],
            'clean' => [
                'data' => [
                    'collection_id' => (int) $collectionId,
                    'style_id' => (int) $styleId,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'material' => $material,
                    'price_cents' => (int) $priceCents,
                    'image' => $currentImage,
                ],
                'stock' => $stock,
                'image_bytes' => $imageBytes,
                'image_ext' => $imageExt,
            ],
        ];
    }

    /** @param array{data: array, stock: array<int,int>, image_bytes: ?string, image_ext: ?string} $clean */
    public function create(array $clean): int
    {
        $data = $clean['data'];
        $newImage = $this->storeImage($clean['image_bytes'], $clean['image_ext']);
        $data['image'] = $newImage ?? $data['image'];

        try {
            return $this->products->create($data, $clean['stock']);
        } catch (\Throwable $e) {
            $this->deleteImageFile($newImage);
            throw $e;
        }
    }

    /** @param array{data: array, stock: array<int,int>, image_bytes: ?string, image_ext: ?string} $clean */
    public function update(int $id, array $clean, ?string $oldImage): void
    {
        $data = $clean['data'];
        $newImage = $this->storeImage($clean['image_bytes'], $clean['image_ext']);
        $data['image'] = $newImage ?? $oldImage;

        try {
            $this->products->update($id, $data, $clean['stock']);
        } catch (\Throwable $e) {
            $this->deleteImageFile($newImage);
            throw $e;
        }
        if ($newImage !== null) {
            $this->deleteImageFile($oldImage);
        }
    }

    /** @throws DomainException si el producto no existe o ya consta en algún pedido */
    public function delete(int $id): void
    {
        $product = $this->products->find($id);
        if ($product === null) {
            throw new DomainException('El producto no existe.');
        }
        if ($this->products->hasOrders($id)) {
            throw new DomainException('No se puede eliminar «' . $product['name'] . '» porque aparece en pedidos. Pon el stock de todas las tallas a 0 para retirarlo del catálogo.');
        }
        $this->products->delete($id);
        $this->deleteImageFile($product['image'] !== null ? (string) $product['image'] : null);
    }

    /** "64,90" o "64.90" → 6490. Devuelve null si no es un importe válido. */
    public static function parsePrice(string $input): ?int
    {
        $input = str_replace(',', '.', trim($input));
        if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $input)) {
            return null;
        }

        return (int) round(((float) $input) * 100);
    }

    public static function slugify(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} [bytes, extensión, error] */
    private function readUpload(?UploadedFileInterface $upload): array
    {
        if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
            return [null, null, null];
        }
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            return [null, null, 'No se pudo subir la imagen. Inténtalo de nuevo.'];
        }
        if (($upload->getSize() ?? 0) > self::MAX_IMAGE_BYTES) {
            return [null, null, 'La imagen pesa más de 2 MB.'];
        }

        $bytes = (string) $upload->getStream();
        $info = $bytes !== '' ? @getimagesizefromstring($bytes) : false;
        $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
        if ($info === false || !isset($extensions[$info[2]])) {
            return [null, null, 'La imagen debe ser JPG, PNG o WebP.'];
        }

        return [$bytes, $extensions[$info[2]], null];
    }

    /** Guarda la imagen subida con un nombre aleatorio y devuelve su ruta pública. */
    private function storeImage(?string $bytes, ?string $extension): ?string
    {
        if ($bytes === null || $extension === null) {
            return null;
        }
        $dir = $this->publicDir . self::IMAGE_DIR;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new DomainException('No se pudo crear la carpeta de imágenes.');
        }
        $file = self::UPLOAD_PREFIX . bin2hex(random_bytes(8)) . '.' . $extension;
        if (@file_put_contents($dir . '/' . $file, $bytes) === false) {
            throw new DomainException('No se pudo guardar la imagen en el servidor.');
        }

        return self::IMAGE_DIR . '/' . $file;
    }

    /** Solo borra imágenes subidas desde el back-office; las del catálogo inicial no se tocan. */
    private function deleteImageFile(?string $path): void
    {
        if ($path === null || !str_starts_with($path, self::IMAGE_DIR . '/' . self::UPLOAD_PREFIX)) {
            return;
        }
        $file = $this->publicDir . $path;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
