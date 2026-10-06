<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\InvoiceService;
use App\Services\OrderService;
use App\Services\SupportService;
use App\Support\Csrf;
use App\Support\Format;
use App\Support\OrderStatus;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Confirmación y factura del pedido para el cliente.
 * Los códigos son predecibles (ZAP-fecha-secuencia), así que solo se muestran a la sesión que hizo el pedido o a un admin.
 */
final class OrderController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly OrderService $orders,
        private readonly InvoiceService $invoices,
        private readonly SupportService $support
    ) {}

    public function show(Request $request, Response $response, array $args): Response
    {
        $detail = $this->accessible((string) ($args['code'] ?? ''));
        if ($detail === null) {
            return $this->notFound($response);
        }
        $success = $_SESSION['_flash_success'] ?? null;
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);

        $supportErrors = $_SESSION['_support_errors'] ?? [];
        $supportOld = $_SESSION['_support_old'] ?? [];
        unset($_SESSION['_support_errors'], $_SESSION['_support_old']);

        return $this->view->render($response, 'order.php', [
            'pageTitle' => 'Pedido ' . $detail['order']['code'] . ' - Zapatero',
            'detail' => $detail,
            'success' => $success,
            'error' => $error,
            'csrfToken' => Csrf::token(),
            'supportErrors' => $supportErrors,
            'supportOld' => $supportOld,
            'supportSubjects' => SupportService::SUBJECTS,
        ]);
    }

    /** Solicitud de soporte / postventa del cliente sobre su pedido (evento support.requested). */
    public function support(Request $request, Response $response, array $args): Response
    {
        $code = (string) ($args['code'] ?? '');
        $detail = $this->accessible($code);
        if ($detail === null) {
            return $this->notFound($response);
        }
        $back = '/pedido/' . rawurlencode($code) . '#soporte';
        $body = (array) $request->getParsedBody();
        if (!Csrf::valid($body)) {
            $_SESSION['_flash_error'] = 'La sesión del formulario caducó. Inténtalo de nuevo.';

            return $response->withHeader('Location', $back)->withStatus(303);
        }

        $result = $this->support->request(
            $detail['order'],
            $body,
            session_id() ?: null,
            ((int) ($_SESSION['user_id'] ?? 0)) > 0 ? (int) $_SESSION['user_id'] : null
        );
        if ($result['errors'] !== []) {
            $_SESSION['_support_errors'] = $result['errors'];
            $_SESSION['_support_old'] = ['subject' => (string) ($body['subject'] ?? ''), 'message' => (string) ($body['message'] ?? '')];
        } else {
            $_SESSION['_flash_success'] = 'Hemos recibido tu solicitud (n.º ' . $result['ticket_id'] . '). Te responderemos por email.';
        }

        return $response->withHeader('Location', $back)->withStatus(303);
    }

    public function invoice(Request $request, Response $response, array $args): Response
    {
        $detail = $this->accessible((string) ($args['code'] ?? ''));
        if ($detail === null || ($detail['payment']['status'] ?? '') !== 'approved') {
            return $this->notFound($response);
        }

        return $this->view->render($response, 'invoice-page.php', [
            'pageTitle' => 'Factura ' . InvoiceService::number((string) $detail['order']['code']) . ' - Zapatero',
            'detail' => $detail,
            'invoiceHtml' => $this->invoices->fragment($detail),
        ]);
    }

    /** Área privada del cliente: lista de sus pedidos con el estado actual. */
    public function mine(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return $response->withHeader('Location', '/login?next=' . rawurlencode('/mis-pedidos'))->withStatus(303);
        }

        return $this->view->render($response->withHeader('Cache-Control', 'no-store'), 'account.php', [
            'pageTitle' => 'Mis pedidos - Zapatero',
            'rows' => $this->orders->ordersForUser($userId),
        ]);
    }

    /** JSON con el estado actual de cada pedido del cliente (la página lo consulta cada pocos segundos). */
    public function statuses(Request $request, Response $response): Response
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId < 1) {
            return $this->json($response, ['error' => 'auth'], 401);
        }
        $orders = array_map(static fn (array $row): array => [
            'code' => (string) $row['code'],
            'status' => (string) $row['status'],
            'label' => OrderStatus::label((string) $row['status']),
        ], $this->orders->ordersForUser($userId));

        return $this->json($response, ['orders' => $orders]);
    }

    /** JSON con el estado y el historial de un pedido (solo para quien puede verlo). */
    public function status(Request $request, Response $response, array $args): Response
    {
        $detail = $this->accessible((string) ($args['code'] ?? ''));
        if ($detail === null) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        $status = (string) $detail['order']['status'];
        $timeline = array_map(static fn (array $step): array => [
            'status' => $step['status'],
            'label' => OrderStatus::label($step['status']),
            'at' => Format::date($step['at']),
        ], OrderService::statusTimeline($detail['events']));

        return $this->json($response, [
            'status' => $status,
            'label' => OrderStatus::label($status),
            'timeline' => $timeline,
        ]);
    }

    /** Un pedido es visible para la sesión que lo hizo, para su dueño (usuario con sesión) y para un administrador. */
    private function accessible(string $code): ?array
    {
        $detail = $this->orders->detail($code);
        if ($detail === null) {
            return null;
        }
        $inSession = in_array($code, (array) ($_SESSION['orders'] ?? []), true);
        $isAdmin = !empty($_SESSION['admin_id']);
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $isOwner = $userId > 0 && (int) ($detail['order']['user_id'] ?? 0) === $userId;

        return ($inSession || $isAdmin || $isOwner) ? $detail : null;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStatus($status);
    }

    private function notFound(Response $response): Response
    {
        return $this->view->render($response->withStatus(404), 'not-found.php', ['pageTitle' => 'Pedido no encontrado - Zapatero']);
    }
}
