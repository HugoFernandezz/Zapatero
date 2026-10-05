<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SupportService;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class SupportController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly SupportService $support
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'support.php', [
            'pageTitle' => 'Soporte - Zapatero',
            'error' => null,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $orderCode = trim((string) ($data['order_code'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        try {
            $ticketId = $this->support->createRequest($orderCode !== '' ? $orderCode : null, $subject, $message);
        } catch (InvalidArgumentException $e) {
            return $this->view->render($response->withStatus(400), 'support.php', [
                'pageTitle' => 'Soporte - Zapatero',
                'error' => $e->getMessage(),
                'old' => ['order_code' => $orderCode, 'subject' => $subject, 'message' => $message],
            ]);
        }

        return $this->view->render($response->withStatus(201), 'support-sent.php', [
            'pageTitle' => 'Solicitud enviada - Zapatero',
            'ticketId' => $ticketId,
        ]);
    }

    public function incidents(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'incidents.php', [
            'pageTitle' => 'Incidencias - Zapatero',
            'tickets' => $this->support->allTickets(),
        ]);
    }

    public function createIncident(Request $request, Response $response, array $args): Response
    {
        return $this->change($response, fn () => $this->support->createIncident((int) $args['id']));
    }

    public function resolveIncident(Request $request, Response $response, array $args): Response
    {
        return $this->change($response, fn () => $this->support->resolveIncident((int) $args['id']));
    }

    private function change(Response $response, callable $action): Response
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            $response->getBody()->write(htmlspecialchars($e->getMessage()));

            return $response->withStatus(400);
        }

        return $response->withHeader('Location', '/admin/incidencias')->withStatus(302);
    }
}
