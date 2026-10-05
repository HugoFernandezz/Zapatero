<?php

declare(strict_types=1);

use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();

$path = $root . '/' . ($_ENV['DB_PATH'] ?? 'storage/zapatero.sqlite');
$pdo = Database::connect($path);

seedCatalog($pdo);

$products = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "Seed de catálogo aplicado en $path con $products productos" . PHP_EOL;

function seedCatalog(PDO $pdo): void
{
    $collections = [
        ['Urban Graffiti', 'urban-graffiti', 'Trazos urbanos, color potente y energía de calle.'],
        ['Botanical', 'botanical', 'Motivos vegetales, tonos frescos y composiciones orgánicas.'],
        ['Retro Pixel', 'retro-pixel', 'Inspiración arcade con patrones pixelados y contrastes vivos.'],
        ['Geometric', 'geometric', 'Líneas limpias, módulos gráficos y bloques de color.'],
    ];

    $styles = [
        ['Low-top', 'low-top'],
        ['High-top', 'high-top'],
        ['Slip-on', 'slip-on'],
    ];

    $products = [
        ['urban-graffiti', 'low-top', 'Avenida Neón', 'avenida-neon', 'Zapatilla low-top ligera con laterales de lona técnica y estampado inspirado en rótulos nocturnos.', 'Lona técnica reciclada, forro textil y suela de caucho flexible.', 6490, '/assets/img/products/avenida-neon.webp'],
        ['urban-graffiti', 'high-top', 'Muro Cobalto', 'muro-cobalto', 'Bota urbana de caña media con gráfica azul intensa y refuerzo acolchado en el tobillo.', 'Sarga de algodón, microfibra vegana y suela cupsole.', 7490, '/assets/img/products/muro-cobalto.webp'],
        ['urban-graffiti', 'slip-on', 'Spray Sunset', 'spray-sunset', 'Slip-on cómoda para diario con degradado cálido y elasticidad justa para calzar rápido.', 'Canvas orgánico, elásticos laterales y plantilla de espuma.', 5790, '/assets/img/products/spray-sunset.webp'],
        ['botanical', 'low-top', 'Liana Verde', 'liana-verde', 'Low-top fresca con dibujo de hojas entrelazadas y puntera reforzada para uso continuo.', 'Algodón orgánico, serraje sintético y caucho natural.', 6290, '/assets/img/products/liana-verde.webp'],
        ['botanical', 'high-top', 'Orquídea Noche', 'orquidea-noche', 'High-top con estampado floral oscuro, cordones planos y cuello acolchado.', 'Lona premium, forro transpirable y suela vulcanizada.', 7690, '/assets/img/products/orquidea-noche.webp'],
        ['botanical', 'slip-on', 'Jardín Claro', 'jardin-claro', 'Slip-on clara con pequeñas ilustraciones botánicas, pensada para looks sencillos.', 'Canvas lavado, plantilla memory foam y goma antideslizante.', 5590, '/assets/img/products/jardin-claro.webp'],
        ['retro-pixel', 'low-top', 'Arcade Runner', 'arcade-runner', 'Low-top con patrón de píxeles y laterales acolchados para caminar todo el día.', 'Ripstop textil, malla transpirable y suela EVA.', 6890, '/assets/img/products/arcade-runner.webp'],
        ['retro-pixel', 'high-top', 'Pixel Pop', 'pixel-pop', 'High-top expresiva con bloques de color retro y lengüeta acolchada.', 'Lona gruesa, refuerzos sintéticos y caucho de alta tracción.', 7890, '/assets/img/products/pixel-pop.webp'],
        ['retro-pixel', 'slip-on', 'Bit Sunset', 'bit-sunset', 'Slip-on con mosaico pixelado en tonos naranja y rosa, fácil de combinar.', 'Canvas suave, elásticos textiles y suela ligera.', 5890, '/assets/img/products/bit-sunset.webp'],
        ['geometric', 'low-top', 'Vector Mono', 'vector-mono', 'Low-top minimalista con patrón lineal en blanco y negro y perfil bajo.', 'Microfibra vegana, forro textil y suela de caucho.', 6590, '/assets/img/products/vector-mono.webp'],
        ['geometric', 'high-top', 'Prisma Coral', 'prisma-coral', 'High-top con paneles geométricos en coral, verde y grafito.', 'Lona técnica, cuello acolchado y suela vulcanizada.', 7790, '/assets/img/products/prisma-coral.webp'],
        ['geometric', 'slip-on', 'Módulo Arena', 'modulo-arena', 'Slip-on de tonos neutros con módulos gráficos para uso diario.', 'Canvas reciclado, plantilla extraíble y goma flexible.', 5690, '/assets/img/products/modulo-arena.webp'],
    ];

    $pdo->beginTransaction();

    try {
        $insertCollection = $pdo->prepare(
            'INSERT OR IGNORE INTO collections (name, slug, description) VALUES (:name, :slug, :description)'
        );
        foreach ($collections as [$name, $slug, $description]) {
            $insertCollection->execute(['name' => $name, 'slug' => $slug, 'description' => $description]);
        }

        $insertStyle = $pdo->prepare('INSERT OR IGNORE INTO styles (name, slug) VALUES (:name, :slug)');
        foreach ($styles as [$name, $slug]) {
            $insertStyle->execute(['name' => $name, 'slug' => $slug]);
        }

        $collectionIds = idsBySlug($pdo, 'collections');
        $styleIds = idsBySlug($pdo, 'styles');

        $insertProduct = $pdo->prepare(
            'INSERT OR IGNORE INTO products
                (collection_id, style_id, name, slug, description, material, price_cents, image)
             VALUES
                (:collection_id, :style_id, :name, :slug, :description, :material, :price_cents, :image)'
        );
        $findProduct = $pdo->prepare('SELECT id FROM products WHERE slug = :slug');
        $insertVariant = $pdo->prepare(
            'INSERT OR IGNORE INTO product_variants (product_id, size_eu, stock, sku)
             VALUES (:product_id, :size_eu, :stock, :sku)'
        );

        foreach ($products as $index => [$collection, $style, $name, $slug, $description, $material, $price, $image]) {
            $insertProduct->execute([
                'collection_id' => $collectionIds[$collection],
                'style_id' => $styleIds[$style],
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'material' => $material,
                'price_cents' => $price,
                'image' => $image,
            ]);

            $findProduct->execute(['slug' => $slug]);
            $productId = (int) $findProduct->fetchColumn();

            foreach (range(36, 46) as $size) {
                $stock = (($index + $size) % 6 === 0) ? 0 : (($index + $size) % 5) + 2;
                $insertVariant->execute([
                    'product_id' => $productId,
                    'size_eu' => $size,
                    'stock' => $stock,
                    'sku' => sprintf('ZAP-%s-%d', strtoupper(str_replace('-', '', $slug)), $size),
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
}

function idsBySlug(PDO $pdo, string $table): array
{
    $rows = $pdo->query("SELECT id, slug FROM {$table}")->fetchAll();
    $ids = [];

    foreach ($rows as $row) {
        $ids[$row['slug']] = (int) $row['id'];
    }

    return $ids;
}
