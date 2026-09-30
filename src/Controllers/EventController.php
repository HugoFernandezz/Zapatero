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
        $events = $this->events->all();

        return $this->view->render($response, 'events.php', [
            'pageTitle' => 'Eventos - Zapatero',
            'events' => $events,
        ]);
    }
}