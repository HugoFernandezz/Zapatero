<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CatalogRepository;

final class CatalogService
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    public function homeData(): array
    {
        return [
            'collections' => $this->catalog->collections(),
            'featuredProducts' => $this->catalog->featuredProducts(4),
        ];
    }

    public function catalogData(array $query): array
    {
        $filters = $this->normalizeFilters($query);

        return [
            'collections' => $this->catalog->collections(),
            'styles' => $this->catalog->styles(),
            'sizes' => range(36, 46),
            'filters' => $filters,
            'products' => $this->catalog->products($filters),
        ];
    }

    public function productBySlug(string $slug): ?array
    {
        return $this->catalog->productBySlug($slug);
    }

    private function normalizeFilters(array $query): array
    {
        $collection = trim((string) ($query['coleccion'] ?? ''));
        if ($collection === '' || !$this->catalog->collectionExists($collection)) {
            $collection = null;
        }

        $style = trim((string) ($query['estilo'] ?? ''));
        if ($style === '' || !$this->catalog->styleExists($style)) {
            $style = null;
        }

        $size = filter_var($query['talla'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($size) || $size < 36 || $size > 46) {
            $size = null;
        }

        return [
            'coleccion' => $collection,
            'estilo' => $style,
            'talla' => $size,
        ];
    }
}
