<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EventService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class EventController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly EventService $events
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'events.php', [
            'pageTitle' => 'Eventos - Zapatero',
            'events' => $this->events->all(),
        ]);
    }

    public function json(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $events = $this->events->all(
            isset($query['type']) ? trim((string) $query['type']) : null,
            isset($query['since']) ? trim((string) $query['since']) : null
        );

        foreach ($events as &$event) {
            $event['payload'] = json_decode((string) $event['payload'], true, 512, JSON_THROW_ON_ERROR);
        }
        unset($event);

        $response->getBody()->write(json_encode($events, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function csv(Request $request, Response $response): Response
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'type', 'occurred_at', 'session_id', 'user_id', 'payload'], ',', '"', '');

        foreach ($this->events->all() as $event) {
            fputcsv($handle, [
                $event['id'],
                $event['type'],
                $event['occurred_at'],
                $event['session_id'],
                $event['user_id'],
                $event['payload'],
            ], ',', '"', '');
        }

        rewind($handle);
        $response->getBody()->write(stream_get_contents($handle));
        fclose($handle);

        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="events.csv"');
    }
}
