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
use Slim\Views\PhpRenderer;

return function (App $app, PDO $pdo, PhpRenderer $view): void {

    /*
     * =========================
     * CATÁLOGO
     * =========================
     */

    $catalogService = new CatalogService(
        new CatalogRepository($pdo)
    );


    /*
     * =========================
     * M5 - EVENTOS
     * =========================
     */

    $eventService = new EventService(
        new EventRepository($pdo)
    );


    /*
     * Función para registrar eventos
     */

    $recordEvent = function (
        string $type,
        array $payload = [],
        ?Request $request = null
    ) use ($eventService): void {

        $sessionId = $request?->getAttribute('session_id');

        $eventService->record(
            $type,
            $payload,
            is_string($sessionId) ? $sessionId : null
        );
    };


    /*
     * =========================
     * M5 - SOPORTE
     * =========================
     */

    $supportService = new SupportService(
        new SupportRepository($pdo),
        $eventService
    );

    $supportController = new SupportController(
        $view,
        $supportService
    );


    /*
     * =========================
     * PÁGINA PRINCIPAL
     * =========================
     */

    $app->get('/', [
        new HomeController(
            $view,
            $catalogService
        ),
        'index'
    ]);


    /*
     * =========================
     * CATÁLOGO
     * =========================
     */

    $app->get('/catalogo', [
        new CatalogController(
            $view,
            $catalogService
        ),
        'index'
    ]);


    /*
     * =========================
     * PRODUCTO
     * =========================
     */

    $app->get('/producto/{slug}', [
        new ProductController(
            $view,
            $catalogService,
            $recordEvent
        ),
        'show'
    ]);


    /*
     * =========================
     * M5 - API DE EVENTOS
     * =========================
     */
     $app->get('/api/events', function (
        Request $req,
        Response $res
    ) use ($eventService) {

        $query = $req->getQueryParams();

        $type = isset($query['type'])
            ? trim((string) $query['type'])
            : null;

        $since = isset($query['since'])
            ? trim((string) $query['since'])
            : null;

        $events = $eventService->all(
            $type,
            $since
        );

        foreach ($events as &$event) {
            $event['payload'] = json_decode(
                (string) $event['payload'],
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        unset($event);

        $res->getBody()->write(
            json_encode(
                $events,
                JSON_UNESCAPED_UNICODE |
                JSON_THROW_ON_ERROR
            )
        );

        return $res->withHeader(
            'Content-Type',
            'application/json'
        );
    });


    /*
     * =========================
     * M5 - CSV DE EVENTOS
     * =========================
     */
     $app->get('/api/events.csv', function (
        Request $req,
        Response $res
    ) use ($eventService) {

        $events = $eventService->all();

        $handle = fopen(
            'php://temp',
            'r+'
        );

        fputcsv($handle, [
            'id',
            'type',
            'occurred_at',
            'session_id',
            'user_id',
            'payload'
        ], ',', '"', '');

        foreach ($events as $event) {
            fputcsv($handle, [
                $event['id'],
                $event['type'],
                $event['occurred_at'],
                $event['session_id'],
                $event['user_id'],
                $event['payload']
            ], ',', '"', '');
        }

        rewind($handle);

        $csv = stream_get_contents($handle);

        fclose($handle);

        $res->getBody()->write(
            $csv
        );

        return $res
            ->withHeader(
                'Content-Type',
                'text/csv'
            )
            ->withHeader(
                'Content-Disposition',
                'attachment; filename="events.csv"'
            );
    });


    /*
     * =========================
     * M5 - PANEL DE EVENTOS
     * =========================
     */

    $app->get('/admin/events', [
        new EventController(
            $view,
            $eventService
        ),
        'index'
    ]);


    /*
     * =========================
     * M5 - SOPORTE
     * =========================
     */

    $app->get('/soporte', [
        $supportController,
        'form'
    ]);

    $app->post('/soporte', [
        $supportController,
        'store'
    ]);


    /*
     * =========================
     * M5 - INCIDENCIAS
     * =========================
     */

    $app->get('/admin/incidencias', [
        $supportController,
        'incidents'
    ]);

    $app->post(
        '/admin/incidencias/{id}/crear',
        [
            $supportController,
            'createIncident'
        ]
    );

    $app->post(
        '/admin/incidencias/{id}/resolver',
        [
            $supportController,
            'resolveIncident'
        ]
    );


    /*
     * =========================
     * HEALTH
     * =========================
     */

    $app->get('/health', function (
        Request $req,
        Response $res
    ) use ($pdo) {

        $res->getBody()->write(
            json_encode([
                'status' => 'ok',
                'php' => PHP_VERSION,
                'sqlite' => $pdo
                    ->query(
                        'SELECT sqlite_version()'
                    )
                    ->fetchColumn(),

                'tables' => (int) $pdo
                    ->query(
                        "SELECT count(*)
                         FROM sqlite_master
                         WHERE type = 'table'
                         AND name NOT LIKE 'sqlite_%'"
                    )
                    ->fetchColumn(),
            ])
        );

        return $res->withHeader(
            'Content-Type',
            'application/json'
        );
    });
};