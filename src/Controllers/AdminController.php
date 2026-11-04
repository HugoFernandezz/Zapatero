<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OrderService;
use App\Support\Csrf;
use App\Support\OrderStatus;
use DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** Back-office de pedidos: listado con filtros, detalle, cambio de estado y reenvío de factura. */
final class AdminController
{
    private const PER_PAGE = 15;

    public function __construct(
        private readonly PhpRenderer $view,
        private readonly OrderService $orders
    ) {}

    public function home(Request $request, Response $response): Response
    {
        return $this->redirect($response, '/admin/pedidos');
    }

    public function orders(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $status = (string) ($params['status'] ?? '');
        if ($status !== '' && !OrderStatus::isValid($status)) {
            $status = '';
        }
        $query = mb_substr(trim((string) ($params['q'] ?? '')), 0, 80);

        $total = $this->orders->countOrders($status, $query);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($params['page'] ?? 1)));
        $rows = $this->orders->listOrders($status, $query, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        [$success, $error] = $this->takeFlash();

        return $this->view->render($response, 'admin/orders.php', [
            'pageTitle' => 'Pedidos - Administración', 'rows' => $rows, 'total' => $total,
            'page' => $page, 'pages' => $pages, 'status' => $status, 'query' => $query,
            'statusCounts' => $this->orders->statusCounts(), 'csrfToken' => Csrf::token(),
            'success' => $success, 'error' => $error,
        ]);
    }

    public function order(Request $request, Response $response, array $args): Response
    {
        $detail = $this->orders->detail((string) ($args['code'] ?? ''));
        if ($detail === null) {
            return $this->view->render($response->withStatus(404), 'not-found.php', ['pageTitle' => 'Pedido no encontrado - Zapatero']);
        }
        [$success, $error] = $this->takeFlash();

        return $this->view->render($response, 'admin/order.php', [
            'pageTitle' => 'Pedido ' . $detail['order']['code'] . ' - Administración',
            'detail' => $detail,
            'nextStatuses' => OrderStatus::adminOptions((string) $detail['order']['status']),
            'csrfToken' => Csrf::token(), 'success' => $success, 'error' => $error,
        ]);
    }

    public function changeStatus(Request $request, Response $response, array $args): Response
    {
        $code = (string) ($args['code'] ?? '');
        $path = '/admin/pedidos/' . rawurlencode($code);
        $body = (array) $request->getParsedBody();
        if (!Csrf::valid($body)) {
            $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.';
            return $this->redirect($response, $path);
        }

        $to = (string) ($body['status'] ?? '');
        try {
            $notified = $this->orders->changeStatus($code, $to, (int) $_SESSION['admin_id'], (string) ($_SESSION['admin_email'] ?? ''), (string) ($body['note'] ?? ''));
            $message = 'Estado actualizado a «' . OrderStatus::label($to) . '».';
            if ($notified === true) {
                $message .= ' Se ha avisado al cliente por email.';
            } elseif ($notified === false) {
                $message .= ' No se pudo avisar al cliente por email (revisa la configuración de correo).';
            }
            $_SESSION['_flash_success'] = $message;
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $path);
    }

    public function resendInvoice(Request $request, Response $response, array $args): Response
    {
        $code = (string) ($args['code'] ?? '');
        $path = '/admin/pedidos/' . rawurlencode($code);
        if (!Csrf::valid((array) $request->getParsedBody())) {
            $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.';
            return $this->redirect($response, $path);
        }

        if ($this->orders->sendInvoice($code, (int) $_SESSION['admin_id'])) {
            $_SESSION['_flash_success'] = 'Factura reenviada al cliente.';
        } else {
            $_SESSION['_flash_error'] = 'No se pudo enviar la factura (¿pago no aprobado o email no válido?).';
        }

        return $this->redirect($response, $path);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function takeFlash(): array
    {
        $flash = [$_SESSION['_flash_success'] ?? null, $_SESSION['_flash_error'] ?? null];
        unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);

        return $flash;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $path)->withStatus(303);
    }
}
