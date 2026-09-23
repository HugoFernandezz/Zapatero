<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class ProductController
{
    /**
     * M5 conectará aquí App\Services\EventService cuando esté disponible.
     * Firma esperada del callable: function (string $type, array $payload, Request $request): void
     */
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly CatalogService $catalog,
        private readonly mixed $recordEvent = null
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $product = $this->catalog->productBySlug((string) $args['slug']);

        if ($product === null) {
            return $this->view->render($response->withStatus(404), 'not-found.php', [
                'pageTitle' => 'Producto no encontrado - Zapatero',
            ]);
        }

        $this->recordProductViewed($request, $product);

        return $this->view->render($response, 'product.php', [
            'pageTitle' => $product['name'] . ' - Zapatero',
            'product' => $product,
        ]);
    }

    private function recordProductViewed(Request $request, array $product): void
    {
        if (!is_callable($this->recordEvent)) {
            return;
        }

        ($this->recordEvent)('product.viewed', [
            'product_id' => (int) $product['id'],
            'slug' => (string) $product['slug'],
        ], $request);
    }
}
