<?php

declare(strict_types=1);

namespace App\BackOffice;

use Closure;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** API JSON del back-office (/bo/api/v1) usada por n8n. Autenticada con BO_API_TOKEN (middleware ApiToken). */
final class BoApiController
{
    /** @param Closure(): BoService $service creación perezosa: la BD del back-office solo se abre si se usa */
    public function __construct(private readonly Closure $service, private readonly ?MetricsService $metrics = null) {}

    private function svc(): BoService
    {
        return ($this->service)();
    }

    /** Ejecuta la acción y traduce BoException al formato de error del contrato. */
    private function run(Response $res, callable $fn, int $okStatus = 200): Response
    {
        try {
            $data = $fn();
            // 201 solo cuando se crea algo; 202 si queda pendiente de sincronizar; 200 para duplicados/actualizaciones.
            $result = $data['result'] ?? null;
            $status = $result === 'pending_sync' ? 202 : ($result === null ? $okStatus : ($result === 'created' ? 201 : 200));

            return $this->json($res, $data, $status);
        } catch (BoException $e) {
            $out = ['error' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details !== []) {
                $out['details'] = $e->details;
            }

            return $this->json($res, $out, $e->status);
        }
    }

    private function body(Request $req): array
    {
        $b = $req->getParsedBody();
        if (is_array($b)) {
            return $b;
        }
        $decoded = json_decode((string) $req->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function ingestOrder(Request $req, Response $res): Response
    {
        return $this->run($res, fn () => $this->svc()->ingestOrder($this->body($req)), 201);
    }

    public function orderStatus(Request $req, Response $res, array $a): Response
    {
        return $this->run($res, fn () => $this->svc()->changeOrderStatus((string) $a['code'], $this->body($req)));
    }

    public function order(Request $req, Response $res, array $a): Response
    {
        return $this->run($res, fn () => $this->svc()->orderView((string) $a['code']));
    }

    public function customer(Request $req, Response $res, array $a): Response
    {
        return $this->run($res, fn () => $this->svc()->customerView((int) $a['id']));
    }

    public function ingestTicket(Request $req, Response $res): Response
    {
        return $this->run($res, fn () => $this->svc()->ingestTicket($this->body($req)), 201);
    }

    public function updateTicket(Request $req, Response $res, array $a): Response
    {
        return $this->run($res, fn () => $this->svc()->updateTicket((string) $a['ref'], $this->body($req)));
    }

    public function propose(Request $req, Response $res): Response
    {
        return $this->run($res, fn () => $this->svc()->proposeAgentAction($this->body($req)), 201);
    }

    /** GET /agent-actions?status=approved  (n8n recoge las aprobadas por una persona para ejecutarlas) */
    public function agentActions(Request $req, Response $res): Response
    {
        return $this->run($res, function () use ($req): array {
            $status = (string) ($req->getQueryParams()['status'] ?? 'approved');
            if (!in_array($status, ['pending_review', 'approved', 'rejected', 'executed', 'executed_simulated', 'execution_failed'], true)) {
                throw BoException::validation(['status' => 'Estado de propuesta no válido.']);
            }
            $rows = $this->svc()->repo()->listAgentActions($status, 50);

            return ['count' => count($rows), 'actions' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'], 'ticket_ref' => $r['ticket_ref'], 'order_code' => $r['order_code'], 'case_type' => $r['case_type'],
                'action_type' => $r['action_type'], 'action' => json_decode((string) $r['action_payload'], true),
                'status' => $r['status'], 'telegram_chat_id' => $r['telegram_chat_id'], 'decided_by' => $r['decided_by'],
            ], $rows)];
        });
    }

    public function executed(Request $req, Response $res, array $a): Response
    {
        $b = $this->body($req);

        return $this->run($res, fn () => $this->svc()->reportExecution((int) $a['id'], (string) ($b['result'] ?? ''), (string) ($b['note'] ?? '')));
    }

    public function telegramLink(Request $req, Response $res): Response
    {
        $b = $this->body($req);

        // Camino normal: token de un solo uso del enlace t.me. (order_code se mantiene solo para pruebas internas con el Bearer del back-office.)
        return $this->run($res, fn () => isset($b['token'])
            ? $this->svc()->linkTelegramByToken((string) $b['token'], (string) ($b['chat_id'] ?? ''))
            : $this->svc()->linkTelegram((string) ($b['order_code'] ?? ''), (string) ($b['chat_id'] ?? '')));
    }

    public function writeLog(Request $req, Response $res): Response
    {
        return $this->run($res, function () use ($req): array {
            $this->svc()->writeLog($this->body($req));

            return ['result' => 'logged'];
        }, 201);
    }

    public function getState(Request $req, Response $res, array $a): Response
    {
        return $this->run($res, fn () => ['key' => $a['key'], 'value' => $this->svc()->getState((string) $a['key'])]);
    }

    public function setState(Request $req, Response $res, array $a): Response
    {
        $b = $this->body($req);

        return $this->run($res, function () use ($a, $b): array {
            $this->svc()->setState((string) $a['key'], (string) ($b['value'] ?? ''));

            return ['key' => $a['key'], 'result' => 'saved'];
        });
    }

    /** GET /metrics: indicadores operativos calculados con datos reales (mismo contenido que el cuadro de mando). */
    public function metrics(Request $req, Response $res): Response
    {
        return $this->run($res, fn () => $this->metrics !== null ? $this->metrics->snapshot() : throw new BoException(501, 'not_implemented', 'Métricas no disponibles.'));
    }

    public function pending(Request $req, Response $res): Response
    {
        return $this->run($res, fn () => ['pending' => $this->svc()->repo()->listPending()]);
    }

    private function json(Response $res, array $data, int $status): Response
    {
        $res->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $res->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
