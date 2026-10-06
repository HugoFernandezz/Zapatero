<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API de eventos para otros sistemas (Tarea 2): GET /api/events y /api/events.csv, con ?type= y ?since=.
 * Si EVENTS_API_TOKEN está definido en .env, hay que enviarlo en la cabecera X-Api-Token.
 */
final class EventApiController
{
    public function __construct(
        private readonly AdminEventController $events,
        private readonly string $token
    ) {
    }

    public function json(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'json');
    }

    public function csv(Request $request, Response $response): Response
    {
        return $this->serve($request, $response, 'csv');
    }

    private function serve(Request $request, Response $response, string $format): Response
    {
        if ($this->token !== '' && !hash_equals($this->token, $request->getHeaderLine('X-Api-Token'))) {
            $response->getBody()->write('{"error":"unauthorized"}');

            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        return $this->events->export($request, $response, ['format' => $format]);
    }
}
