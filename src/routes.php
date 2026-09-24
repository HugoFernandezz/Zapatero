<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Repositories\CatalogRepository;
use App\Services\CatalogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Views\PhpRenderer;

return function (App $app, PDO $pdo, PhpRenderer $view): void {
    $catalogService = new CatalogService(new CatalogRepository($pdo));
    $recordEvent = null; // M5 conectara aqui EventService::record('product.viewed', payload).

    $app->get('/', [new HomeController($view, $catalogService), 'index']);
    $app->get('/catalogo', [new CatalogController($view, $catalogService), 'index']);
    $app->get('/producto/{slug}', [new ProductController($view, $catalogService, $recordEvent), 'show']);

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
