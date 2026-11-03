<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\EventRepository;
use App\Services\OrderService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * API de integración de solo lectura (v1) para que n8n consuma los eventos y consulte pedidos.
 * Contrato documentado en docs/contrato-integracion.md. Solo expone datos de prueba y minimiza los personales.
 */
final class ApiController
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly EventRepository $events,
        private readonly OrderService $orders
    ) {}

    /** GET /api/v1/events?after_id=0&limit=100&type=order.created */
    public function events(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $afterRaw = (string) ($q['after_id'] ?? '0');
        $limitRaw = (string) ($q['limit'] ?? (string) self::DEFAULT_LIMIT);
        $type = (string) ($q['type'] ?? '');

        if (preg_match('/^\d{1,12}$/', $afterRaw) !== 1) {
            return $this->json($response, ['error' => 'invalid_after_id', 'message' => 'after_id debe ser un entero >= 0.'], 400);
        }
        if (preg_match('/^\d{1,4}$/', $limitRaw) !== 1 || (int) $limitRaw < 1 || (int) $limitRaw > self::MAX_LIMIT) {
            return $this->json($response, ['error' => 'invalid_limit', 'message' => 'limit debe estar entre 1 y ' . self::MAX_LIMIT . '.'], 400);
        }
        if ($type !== '' && preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $type) !== 1) {
            return $this->json($response, ['error' => 'invalid_type', 'message' => 'Tipo de evento no válido.'], 400);
        }

        $limit = (int) $limitRaw;
        $rows = $this->events->after((int) $afterRaw, $limit, $type);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $items = array_map(static function (array $row): array {
            $payload = json_decode((string) $row['payload'], true);

            return [
                'event_id' => (string) $row['event_id'],
                'cursor_id' => (int) $row['id'],
                'schema_version' => (int) $row['schema_version'],
                'type' => (string) $row['type'],
                'occurred_at' => (string) $row['occurred_at'],
                'session_id' => $row['session_id'],
                'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                'payload' => is_array($payload) ? $payload : new \stdClass(),
            ];
        }, $rows);

        return $this->json($response, [
            'api_version' => 'v1',
            'count' => count($items),
            'has_more' => $hasMore,
            // El consumidor guarda este valor y lo manda como after_id en la siguiente consulta.
            'next_after_id' => $items === [] ? (int) $afterRaw : $items[count($items) - 1]['cursor_id'],
            'events' => $items,
        ]);
    }

    /** GET /api/v1/orders/{code}: pedido completo normalizado (cliente de prueba, líneas, pago y tickets). */
    public function order(Request $request, Response $response, array $args): Response
    {
        $code = (string) ($args['code'] ?? '');
        if (preg_match('/^ZAP-\d{8}-\d{4}$/', $code) !== 1) {
            return $this->json($response, ['error' => 'invalid_order_code', 'message' => 'Formato esperado: ZAP-AAAAMMDD-NNNN.'], 400);
        }
        $detail = $this->orders->detail($code);
        if ($detail === null) {
            return $this->json($response, ['error' => 'order_not_found', 'message' => 'Pedido inexistente.'], 404);
        }

        $o = $detail['order'];
        $c = $detail['customer'];
        $p = $detail['payment'];
        // Instante del último cambio de estado: permite al consumidor ordenar y descartar eventos antiguos.
        $statusAt = (string) $o['created_at'];
        foreach ($detail['events'] as $e) {
            if ($e['type'] === 'order.status_changed' && (string) $e['occurred_at'] > $statusAt) {
                $statusAt = (string) $e['occurred_at'];
            }
        }

        return $this->json($response, [
            'api_version' => 'v1',
            'order' => [
                'order_code' => (string) $o['code'],
                'status' => (string) $o['status'],
                'created_at' => (string) $o['created_at'],
                'status_updated_at' => $statusAt,
                'currency' => 'EUR',
                'subtotal_cents' => (int) $o['subtotal_cents'],
                'discount_cents' => (int) $o['discount_cents'],
                'shipping_cents' => (int) $o['shipping_cents'],
                'tax_cents' => (int) $o['tax_cents'],
                'total_cents' => (int) $o['total_cents'],
                'discount_code' => $o['discount_code'] ?? null,
                'customer_user_id' => $o['user_id'] !== null ? (int) $o['user_id'] : null,
                'customer' => [
                    'full_name' => (string) ($c['full_name'] ?? ''),
                    'email' => (string) ($c['email'] ?? ''),
                    'city' => (string) ($c['city'] ?? ''),
                    'province' => (string) ($c['province'] ?? ''),
                ],
                'items' => array_map(static fn (array $i): array => [
                    'sku' => (string) $i['sku'],
                    'name' => (string) $i['name'],
                    'size_eu' => (int) $i['size_eu'],
                    'quantity' => (int) $i['quantity'],
                    'unit_price_cents' => (int) $i['unit_price_cents'],
                ], $detail['items']),
                'payment' => $p === null ? null : [
                    'method' => (string) $p['method'],
                    'status' => (string) $p['status'],
                    'reference' => (string) $p['reference'],
                    'created_at' => (string) $p['created_at'],
                ],
                'tickets' => array_map(static fn (array $t): array => [
                    'ticket_id' => (int) $t['id'],
                    'subject' => (string) $t['subject'],
                    'message' => (string) $t['message'],
                    'status' => (string) $t['status'],
                    'created_at' => (string) $t['created_at'],
                ], $detail['tickets']),
            ],
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json; charset=utf-8');
    }
}
