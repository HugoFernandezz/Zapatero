<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CatalogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function collections(): array
    {
        return $this->pdo
            ->query('SELECT id, name, slug, description FROM collections ORDER BY name')
            ->fetchAll();
    }

    public function styles(): array
    {
        return $this->pdo
            ->query('SELECT id, name, slug FROM styles ORDER BY name')
            ->fetchAll();
    }

    public function availableSizes(): array
    {
        $rows = $this->pdo
            ->query('SELECT DISTINCT size_eu FROM product_variants WHERE stock > 0 ORDER BY size_eu')
            ->fetchAll();

        return array_map(static fn (array $row): int => (int) $row['size_eu'], $rows);
    }

    public function collectionExists(string $slug): bool
    {
        return $this->existsBySlug('collections', $slug);
    }

    public function styleExists(string $slug): bool
    {
        return $this->existsBySlug('styles', $slug);
    }

    public function featuredProducts(int $limit = 4): array
    {
        $statement = $this->pdo->prepare($this->productSummarySql() . ' GROUP BY p.id ORDER BY p.id LIMIT :limit');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function products(array $filters): array
    {
        $where = [];
        $params = [];

        if ($filters['coleccion'] !== null) {
            $where[] = 'c.slug = :coleccion';
            $params['coleccion'] = $filters['coleccion'];
        }

        if ($filters['estilo'] !== null) {
            $where[] = 's.slug = :estilo';
            $params['estilo'] = $filters['estilo'];
        }

        if ($filters['talla'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1
                FROM product_variants pv_filter
                WHERE pv_filter.product_id = p.id
                  AND pv_filter.size_eu = :talla
                  AND pv_filter.stock > 0
            )';
            $params['talla'] = $filters['talla'];
        }

        $sql = $this->productSummarySql();
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY p.id ORDER BY c.name, p.name';

        $statement = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    public function productBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                p.id,
                p.name,
                p.slug,
                p.description,
                p.material,
                p.price_cents,
                p.image,
                c.name AS collection_name,
                c.slug AS collection_slug,
                s.name AS style_name,
                s.slug AS style_slug
             FROM products p
             INNER JOIN collections c ON c.id = p.collection_id
             INNER JOIN styles s ON s.id = p.style_id
             WHERE p.slug = :slug'
        );
        $statement->execute(['slug' => $slug]);
        $product = $statement->fetch();

        if (!$product) {
            return null;
        }

        $variants = $this->pdo->prepare(
            'SELECT id, size_eu, stock, sku
             FROM product_variants
             WHERE product_id = :product_id
             ORDER BY size_eu'
        );
        $variants->execute(['product_id' => $product['id']]);

        $product['variants'] = $variants->fetchAll();
        $product['total_stock'] = array_sum(array_map(
            static fn (array $variant): int => (int) $variant['stock'],
            $product['variants']
        ));

        return $product;
    }

    private function existsBySlug(string $table, string $slug): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE slug = :slug LIMIT 1");
        $statement->execute(['slug' => $slug]);

        return (bool) $statement->fetchColumn();
    }

    private function productSummarySql(): string
    {
        return 'SELECT
                p.id,
                p.name,
                p.slug,
                p.description,
                p.price_cents,
                p.image,
                c.name AS collection_name,
                c.slug AS collection_slug,
                s.name AS style_name,
                s.slug AS style_slug,
                SUM(pv.stock) AS total_stock,
                MIN(CASE WHEN pv.stock > 0 THEN pv.size_eu END) AS min_available_size,
                MAX(CASE WHEN pv.stock > 0 THEN pv.size_eu END) AS max_available_size
            FROM products p
            INNER JOIN collections c ON c.id = p.collection_id
            INNER JOIN styles s ON s.id = p.style_id
            INNER JOIN product_variants pv ON pv.product_id = p.id';
    }
}
