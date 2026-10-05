<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Repositories\CatalogRepository;
use App\Repositories\CartRepository;
use App\Services\CatalogService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\EventService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\PhpRenderer;

return function (App $app, PDO $pdo, PhpRenderer $view): void {
    $catalogService = new CatalogService(new CatalogRepository($pdo));
    $events = new EventService($pdo);
    // Compatible con ProductController (pasa Request) y con CartService (pasa el id de sesión).
    $recordEvent = static fn (string $type, array $payload, mixed $context = null) =>
        $events->record($type, $payload, is_string($context) ? $context : session_id());
    $cartService = new CartService(new CartRepository($pdo), $recordEvent);

    $app->get('/', [new HomeController($view, $catalogService), 'index']);
    $app->get('/catalogo', [new CatalogController($view, $catalogService), 'index']);
    $app->get('/producto/{slug}', [new ProductController($view, $catalogService, $recordEvent), 'show']);

    $app->get('/carrito', [new CartController($view, $cartService), 'show']);
    $app->post('/carrito/agregar', [new CartController($view, $cartService), 'add']);
    $app->post('/carrito/actualizar', [new CartController($view, $cartService), 'update']);
    $app->post('/carrito/descuento', [new CartController($view, $cartService), 'discount']);
    $checkout = new CheckoutController($view, new CheckoutService($cartService), $events);
    $app->get('/checkout', [$checkout, 'show']);
    $app->post('/checkout', [$checkout, 'submit']);

    $app->get('/health', function (Request $req, Response $res) use ($pdo) {
        $res->getBody()->write(json_encode([
            'status' => 'ok',
            'php' => PHP_VERSION,
            'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
            'tables' => (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn(),
        ]));

        return $res->withHeader('Content-Type', 'application/json');
    });
};
