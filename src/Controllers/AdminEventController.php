<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\EventRepository;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/**
 * Registro de eventos de negocio: consulta con filtros y exportación JSON/CSV.
 * La exportación es el punto de integración previsto para que otro sistema (Tarea 2) consuma los eventos.
 */
final class AdminEventController
{
    private const PER_PAGE = 25;
    private const EXPORT_LIMIT = 10000;

    /** Eventos mínimos exigidos por el enunciado (se muestran aunque aún no tengan registros). */
    public const REQUIRED = [
        'product.viewed', 'cart.item_added', 'checkout.started', 'order.created', 'payment.simulated', 'support.requested',
    ];

    public function __construct(
        private readonly PhpRenderer $view,
        private readonly EventRepository $events
    ) {}

    public function index(Request $request, Response $response): Response
    {
        $filters = $this->filters($request->getQueryParams());
        $total = $this->events->count($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) ($request->getQueryParams()['page'] ?? 1)));

        return $this->view->render($response->withHeader('Cache-Control', 'no-store'), 'admin/events.php', [
            'pageTitle' => 'Eventos - Administración',
            'rows' => $this->events->search($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => $filters,
            'counts' => $this->events->countsByType(),
            'required' => self::REQUIRED,
        ]);
    }

    public function export(Request $request, Response $response, array $args): Response
    {
        $format = (string) ($args['format'] ?? '');
        $rows = $this->events->search($this->filters($request->getQueryParams()), self::EXPORT_LIMIT);
        $name = 'zapatero-eventos-' . gmdate('Ymd-His') . '.' . $format;

        if ($format === 'json') {
            $events = array_map(static function (array $row): array {
                $payload = json_decode((string) $row['payload'], true);

                return [
                    'id' => (int) $row['id'],
                    'type' => (string) $row['type'],
                    'occurred_at' => (string) $row['occurred_at'],
                    'session_id' => $row['session_id'],
                    'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
                    'payload' => is_array($payload) ? $payload : (string) $row['payload'],
                ];
            }, $rows);
            $body = json_encode(
                ['exported_at' => gmdate('Y-m-d\TH:i:s\Z'), 'count' => count($events), 'events' => $events],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $contentType = 'application/json; charset=utf-8';
        } else {
            $handle = fopen('php://temp', 'r+');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM para que Excel abra bien los acentos
            fputcsv($handle, ['id', 'occurred_at', 'type', 'session_id', 'user_id', 'payload'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($handle, array_map([self::class, 'csvCell'], [
                    $row['id'], $row['occurred_at'], $row['type'], $row['session_id'] ?? '', $row['user_id'] ?? '', $row['payload'],
                ]), ',', '"', '');
            }
            rewind($handle);
            $body = (string) stream_get_contents($handle);
            fclose($handle);
            $contentType = 'text/csv; charset=utf-8';
        }

        $response->getBody()->write((string) $body);

        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Evita la inyección de fórmulas al abrir el CSV en Excel/Calc (celdas que empiezan por = + - @). */
    public static function csvCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $text) === 1 ? "'" . $text : $text;
    }

    /** @return array{since: string, type: string, q: string, from: string, to: string} */
    private function filters(array $params): array
    {
        $type = (string) ($params['type'] ?? '');
        if (preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $type) !== 1) {
            $type = '';
        }
        $date = static function (mixed $value): string {
            $value = (string) $value;
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };

        // since: fecha-hora ISO 8601 UTC (p. ej. 2026-10-06T10:00:00Z) para el sondeo incremental de la API.
        $since = (string) ($params['since'] ?? '');
        if (DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $since) === false) {
            $since = '';
        }

        return [
            'since' => $since,
            'type' => $type,
            'q' => mb_substr(trim((string) ($params['q'] ?? '')), 0, 80),
            'from' => $date($params['from'] ?? ''),
            'to' => $date($params['to'] ?? ''),
        ];
    }
}
