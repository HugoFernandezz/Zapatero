<?php

declare(strict_types=1);

use App\Controllers\CatalogController;
use App\Controllers\EventController;
use App\Controllers\HomeController;
use App\Controllers\ProductController;
use App\Controllers\SupportController;
use App\Repositories\CatalogRepository;
use App\Repositories\EventRepository;
use App\Repositories\SupportRepository;
use App\Services\CatalogService;
use App\Services\EventService;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\PhpRenderer;

return function (App $app, PDO $pdo, PhpRenderer $view): void {
    $catalogService = new CatalogService(new CatalogRepository($pdo));
    $eventService = new EventService(new EventRepository($pdo));
    $supportController = new SupportController($view, new SupportService(new SupportRepository($pdo), $eventService));
    $eventController = new EventController($view, $eventService);

    $recordEvent = function (string $type, array $payload, Request $request) use ($eventService): void {
        $sessionId = $request->getAttribute('session_id');
        $eventService->record($type, $payload, is_string($sessionId) ? $sessionId : null);
    };

    $app->get('/', [new HomeController($view, $catalogService), 'index']);
    $app->get('/catalogo', [new CatalogController($view, $catalogService), 'index']);
    $app->get('/producto/{slug}', [new ProductController($view, $catalogService, $recordEvent), 'show']);

    $app->get('/soporte', [$supportController, 'form']);
    $app->post('/soporte', [$supportController, 'store']);

    $app->get('/api/events', [$eventController, 'json']);
    $app->get('/api/events.csv', [$eventController, 'csv']);

    $app->group('/admin', function (RouteCollectorProxy $admin) use ($eventController, $supportController) {
        $admin->get('/events', [$eventController, 'index']);
        $admin->get('/incidencias', [$supportController, 'incidents']);
        $admin->post('/incidencias/{id}/crear', [$supportController, 'createIncident']);
        $admin->post('/incidencias/{id}/resolver', [$supportController, 'resolveIncident']);
    });

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
