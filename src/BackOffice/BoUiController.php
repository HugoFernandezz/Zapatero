<?php

declare(strict_types=1);

namespace App\BackOffice;

use App\Support\Csrf;
use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** Pantallas del back-office para el personal (solo administradores): pedidos, tickets, agente, integración. */
final class BoUiController
{
    private const PER_PAGE = 20;

    /** @param Closure(): BoService $service */
    public function __construct(private readonly PhpRenderer $view, private readonly Closure $service, private readonly MetricsService $metrics) {}

    private function repo(): BoRepository
    {
        return ($this->service)()->repo();
    }

    public function home(Request $req, Response $res): Response
    {
        $r = $this->repo();

        return $this->view->render($res, 'bo/home.php', [
            'pageTitle' => 'Back-office - Zapatero',
            'orders' => $r->orderStatusCounts(), 'tickets' => $r->ticketStatusCounts(),
            'agent' => $r->agentStatusCounts(), 'log' => $r->logCounts(), 'pending' => $r->pendingCount(),
        ]);
    }

    public function orders(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $status = in_array($q['status'] ?? '', BoService::ORDER_STATUSES, true) ? (string) $q['status'] : '';
        $search = mb_substr(trim((string) ($q['q'] ?? '')), 0, 80);
        $r = $this->repo();
        $total = $r->countOrders($status, $search);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($q['page'] ?? 1)));

        return $this->view->render($res, 'bo/orders.php', [
            'pageTitle' => 'Pedidos - Back-office', 'rows' => $r->listOrders($status, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total, 'page' => $page, 'pages' => $pages, 'status' => $status, 'query' => $search,
        ]);
    }

    public function order(Request $req, Response $res, array $a): Response
    {
        $r = $this->repo();
        $o = $r->order((string) ($a['code'] ?? ''));
        if ($o === null) {
            return $this->view->render($res->withStatus(404), 'not-found.php', ['pageTitle' => 'No encontrado - Zapatero']);
        }

        return $this->view->render($res, 'bo/order.php', [
            'pageTitle' => 'Pedido ' . $o['order_code'] . ' - Back-office', 'o' => $o,
            'lines' => $r->lines((int) $o['id']), 'tickets' => $r->ticketsForOrder((int) $o['id']),
        ]);
    }

    public function tickets(Request $req, Response $res): Response
    {
        $status = in_array($req->getQueryParams()['status'] ?? '', BoService::TICKET_STATUSES, true) ? (string) $req->getQueryParams()['status'] : '';
        $r = $this->repo();

        return $this->view->render($res, 'bo/tickets.php', [
            'pageTitle' => 'Tickets - Back-office', 'rows' => $r->listTickets($status, 50, 0),
            'counts' => $r->ticketStatusCounts(), 'status' => $status, 'csrfToken' => Csrf::token(),
        ]);
    }

    public function agent(Request $req, Response $res): Response
    {
        $status = (string) ($req->getQueryParams()['status'] ?? '');
        $valid = ['pending_review', 'approved', 'rejected', 'executed', 'executed_simulated', 'execution_failed'];
        $status = in_array($status, $valid, true) ? $status : '';
        $r = $this->repo();

        return $this->view->render($res, 'bo/agent.php', [
            'pageTitle' => 'Agente de IA - Back-office', 'rows' => $r->listAgentActions($status, 50),
            'counts' => $r->agentStatusCounts(), 'status' => $status, 'csrfToken' => Csrf::token(),
        ]);
    }

    /** POST /bo/agente/{id}/decidir  (decision = approve | reject, nota opcional). Queda registrado quién y cuándo. */
    public function decide(Request $req, Response $res, array $a): Response
    {
        $body = (array) $req->getParsedBody();
        if (!Csrf::valid($body)) {
            $_SESSION['_flash_error'] = 'La sesión ha caducado. Inténtalo de nuevo.';

            return $this->redirect($res, '/bo/agente');
        }
        $decision = (string) ($body['decision'] ?? '');
        if (!in_array($decision, ['approve', 'reject'], true)) {
            $_SESSION['_flash_error'] = 'Decisión no válida.';

            return $this->redirect($res, '/bo/agente');
        }
        try {
            ($this->service)()->decide((int) ($a['id'] ?? 0), $decision, (string) ($_SESSION['admin_email'] ?? 'admin'), (string) ($body['note'] ?? ''));
            $_SESSION['_flash_success'] = 'Propuesta #' . (int) $a['id'] . ($decision === 'approve' ? ' aprobada.' : ' rechazada.');
        } catch (BoException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
        }

        return $this->redirect($res, '/bo/agente?status=pending_review');
    }

    /** Cuadro de mando operativo: todas las cifras se calculan al vuelo con datos reales del sistema. */
    public function metrics(Request $req, Response $res): Response
    {
        return $this->view->render($res, 'bo/metricas.php', ['pageTitle' => 'Cuadro de mando - Back-office', 'm' => $this->metrics->snapshot()]);
    }

    /** Las mismas métricas en JSON (evidencia verificable y reutilizable por otros sistemas). */
    public function metricsData(Request $req, Response $res): Response
    {
        $res->getBody()->write((string) json_encode($this->metrics->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $res->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store');
    }

    /** POST /bo/tickets/{ref}/estado: iniciar, resolver o reabrir un ticket (alimenta el tiempo medio de resolución). */
    public function ticketStatus(Request $req, Response $res, array $a): Response
    {
        $body = (array) $req->getParsedBody();
        if (!Csrf::valid($body)) {
            $_SESSION['_flash_error'] = 'La sesión ha caducado. Inténtalo de nuevo.';

            return $this->redirect($res, '/bo/tickets');
        }
        $status = (string) ($body['status'] ?? '');
        if (!in_array($status, BoService::TICKET_STATUSES, true)) {
            $_SESSION['_flash_error'] = 'Estado no válido.';

            return $this->redirect($res, '/bo/tickets');
        }
        try {
            ($this->service)()->updateTicket((string) ($a['ref'] ?? ''), ['status' => $status]);
            $_SESSION['_flash_success'] = 'Ticket ' . (string) $a['ref'] . ' → ' . $status . '.';
        } catch (BoException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
        }

        return $this->redirect($res, '/bo/tickets');
    }

    public function integration(Request $req, Response $res): Response
    {
        $q = $req->getQueryParams();
        $status = in_array($q['status'] ?? '', ['ok', 'duplicate', 'rejected', 'error', 'pending_sync', 'stale', 'skipped'], true) ? (string) $q['status'] : '';
        $r = $this->repo();
        $total = $r->countLog($status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($q['page'] ?? 1)));

        return $this->view->render($res, 'bo/integration.php', [
            'pageTitle' => 'Integración - Back-office', 'rows' => $r->listLog($status, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'counts' => $r->logCounts(), 'status' => $status, 'page' => $page, 'pages' => $pages, 'total' => $total,
            'pending' => $r->listPending(30),
        ]);
    }

    private function redirect(Response $res, string $to): Response
    {
        return $res->withHeader('Location', $to)->withStatus(303);
    }
}
