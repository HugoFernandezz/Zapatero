<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use Throwable;

/** Acceso a datos del CRUD de productos del back-office. */
final class ProductAdminRepository
{
    public const SIZES = [36, 37, 38, 39, 40, 41, 42, 43, 44, 45, 46];

    public function __construct(private readonly PDO $pdo) {}

    public function collections(): array
    {
        return $this->pdo->query('SELECT id, name FROM collections ORDER BY name')->fetchAll();
    }

    public function styles(): array
    {
        return $this->pdo->query('SELECT id, name FROM styles ORDER BY name')->fetchAll();
    }

    public function collectionExists(int $id): bool
    {
        return $this->exists('SELECT 1 FROM collections WHERE id = :id', ['id' => $id]);
    }

    public function styleExists(int $id): bool
    {
        return $this->exists('SELECT 1 FROM styles WHERE id = :id', ['id' => $id]);
    }

    public function slugTaken(string $slug, ?int $excludeId): bool
    {
        return $this->exists('SELECT 1 FROM products WHERE slug = :slug AND id <> :id', ['slug' => $slug, 'id' => $excludeId ?? 0]);
    }

    public function skuTaken(string $sku): bool
    {
        return $this->exists('SELECT 1 FROM product_variants WHERE sku = :sku', ['sku' => $sku]);
    }

    /** Listado con colección, estilo y stock total. */
    public function all(): array
    {
        return $this->pdo->query(
            'SELECT p.id, p.name, p.slug, p.price_cents, p.image, c.name AS collection_name, s.name AS style_name,
                    COALESCE(SUM(pv.stock), 0) AS total_stock
             FROM products p
             JOIN collections c ON c.id = p.collection_id
             JOIN styles s ON s.id = p.style_id
             LEFT JOIN product_variants pv ON pv.product_id = p.id
             GROUP BY p.id ORDER BY c.name, p.name'
        )->fetchAll();
    }

    /** @return array<string,mixed>|null producto con la clave "stock" (talla => unidades) */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM products WHERE id = :id');
        $statement->execute(['id' => $id]);
        $product = $statement->fetch();
        if ($product === false) {
            return null;
        }

        $variants = $this->pdo->prepare('SELECT size_eu, stock FROM product_variants WHERE product_id = :id ORDER BY size_eu');
        $variants->execute(['id' => $id]);
        $product['stock'] = [];
        foreach ($variants->fetchAll() as $row) {
            $product['stock'][(int) $row['size_eu']] = (int) $row['stock'];
        }

        return $product;
    }

    /**
     * Crea el producto con una variante por talla (stock indicado) en una sola transacción.
     *
     * @param array{collection_id:int,style_id:int,name:string,slug:string,description:string,material:string,price_cents:int,image:?string} $data
     * @param array<int,int> $stock talla => unidades
     */
    public function create(array $data, array $stock): int
    {
        return $this->transaction(function () use ($data, $stock): int {
            $this->pdo->prepare(
                'INSERT INTO products (collection_id, style_id, name, slug, description, material, price_cents, image)
                 VALUES (:collection_id, :style_id, :name, :slug, :description, :material, :price_cents, :image)'
            )->execute($data);
            $id = (int) $this->pdo->lastInsertId();

            foreach (self::SIZES as $size) {
                $this->pdo->prepare('INSERT INTO product_variants (product_id, size_eu, stock, sku) VALUES (:product, :size, :stock, :sku)')
                    ->execute([
                        'product' => $id,
                        'size' => $size,
                        'stock' => $stock[$size] ?? 0,
                        'sku' => self::sku($data['slug'], $size),
                    ]);
            }

            return $id;
        });
    }

    /**
     * Actualiza el producto y el stock de cada talla. Las variantes no se borran nunca (pueden estar en pedidos):
     * para retirar una talla se pone su stock a 0.
     *
     * @param array<string,mixed> $data mismas claves que create()
     * @param array<int,int>      $stock
     */
    public function update(int $id, array $data, array $stock): void
    {
        $this->transaction(function () use ($id, $data, $stock): void {
            $this->pdo->prepare(
                'UPDATE products SET collection_id = :collection_id, style_id = :style_id, name = :name, slug = :slug,
                        description = :description, material = :material, price_cents = :price_cents, image = :image
                 WHERE id = :id'
            )->execute($data + ['id' => $id]);

            $slug = (string) $data['slug'];
            foreach (self::SIZES as $size) {
                $this->pdo->prepare(
                    'INSERT INTO product_variants (product_id, size_eu, stock, sku) VALUES (:product, :size, :stock, :sku)
                     ON CONFLICT (product_id, size_eu) DO UPDATE SET stock = excluded.stock'
                )->execute(['product' => $id, 'size' => $size, 'stock' => $stock[$size] ?? 0, 'sku' => self::sku($slug, $size) . '-' . $id]);
            }
        });
    }

    public function hasOrders(int $id): bool
    {
        return $this->exists(
            'SELECT 1 FROM order_items oi JOIN product_variants pv ON pv.id = oi.variant_id WHERE pv.product_id = :id LIMIT 1',
            ['id' => $id]
        );
    }

    /** Borra el producto (las variantes y los carritos guardados caen en cascada). */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
    }

    public static function sku(string $slug, int $size): string
    {
        return sprintf('ZAP-%s-%d', strtoupper(str_replace('-', '', $slug)), $size);
    }

    private function exists(string $sql, array $params): bool
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
