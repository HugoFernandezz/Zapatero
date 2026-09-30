<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;
use Throwable;

final class SupportController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly SupportService $support
    ) {
    }

    public function form(
        Request $request,
        Response $response
    ): Response {
        return $this->view->render(
            $response,
            'support.php',
            [
                'pageTitle' => 'Soporte - Zapatero',
                'error' => null,
            ]
        );
    }

    public function store(
        Request $request,
        Response $response
    ): Response {
        $data = (array) $request->getParsedBody();

        $orderCode = trim(
            (string) ($data['order_code'] ?? '')
        );

        $subject = trim(
            (string) ($data['subject'] ?? '')
        );

        $message = trim(
            (string) ($data['message'] ?? '')
        );

        try {
            $ticketId = $this->support->createRequest(
                $orderCode !== '' ? $orderCode : null,
                $subject,
                $message
            );

            $response->getBody()->write(
                '<!DOCTYPE html>
                <html lang="es">
                <head>
                    <meta charset="UTF-8">
                    <title>Soporte enviado</title>
                </head>
                <body>
                    <h1>Solicitud enviada</h1>
                    <p>Tu solicitud de soporte ha sido registrada.</p>
                    <p>Ticket: ' .
                    htmlspecialchars((string) $ticketId) .
                    '</p>
                    <p><a href="/soporte">Volver a soporte</a></p>
                </body>
                </html>'
            );

            return $response->withStatus(201);
        } catch (Throwable $e) {
            return $this->view->render(
                $response,
                'support.php',
                [
                    'pageTitle' => 'Soporte - Zapatero',
                    'error' => $e->getMessage(),
                    'old' => [
                        'order_code' => $orderCode,
                        'subject' => $subject,
                        'message' => $message,
                    ],
                ]
            )->withStatus(400);
        }
    }

    public function incidents(
        Request $request,
        Response $response
    ): Response {
        return $this->view->render(
            $response,
            'incidents.php',
            [
                'pageTitle' => 'Incidencias - Zapatero',
                'tickets' => $this->support->allTickets(),
            ]
        );
    }

    public function createIncident(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $ticketId = (int) ($args['id'] ?? 0);

        try {
            $this->support->createIncident($ticketId);

            return $response
                ->withHeader(
                    'Location',
                    '/admin/incidencias'
                )
                ->withStatus(302);
        } catch (Throwable $e) {
            $response->getBody()->write(
                htmlspecialchars($e->getMessage())
            );

            return $response->withStatus(400);
        }
    }

    public function resolveIncident(
        Request $request,
        Response $response,
        array $args
    ): Response {
        $ticketId = (int) ($args['id'] ?? 0);

        try {
            $this->support->resolveIncident($ticketId);

            return $response
                ->withHeader(
                    'Location',
                    '/admin/incidencias'
                )
                ->withStatus(302);
        } catch (Throwable $e) {
            $response->getBody()->write(
                htmlspecialchars($e->getMessage())
            );

            return $response->withStatus(400);
        }
    }
}