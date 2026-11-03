<?php

declare(strict_types=1);

namespace App\BackOffice;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Cuadro de mando operativo. TODAS las cifras se calculan en el momento a partir de datos generados por el sistema:
 * eventos y pedidos de la tienda (BD de la tienda) y pedidos, tickets, log de integración y propuestas del agente (BD del back-office).
 * No hay ningún dato escrito a mano ni ningún CSV estático.
 */
final class MetricsService
{
    private const FUNNEL = [
        'product.viewed' => 'Producto visto',
        'cart.item_added' => 'Añadido al carrito',
        'checkout.started' => 'Checkout iniciado',
        'order.created' => 'Pedido creado',
    ];
    private const CHART_DAYS = 30;

    /** @param Closure(): BoService $boService creación perezosa de la BD del back-office */
    public function __construct(private readonly PDO $shop, private readonly Closure $boService) {}

    /** @return array<string, mixed> */
    public function snapshot(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $bo = ($this->boService)()->repo();

        $totals = $bo->orderTotals();
        $shopOrders = $this->shopOrderCount();
        $log = $bo->logCounts();
        $failed = (int) ($log['rejected'] ?? 0) + (int) ($log['error'] ?? 0);
        $logTotal = (int) array_sum($log);
        $agent = $bo->agentStats();
        $decided = $agent['approved'] + $agent['rejected'];
        $funnel = $this->funnel();
        $viewed = $funnel[0]['sessions'] ?? 0;
        $ordered = $funnel[3]['sessions'] ?? 0;

        return [
            'generated_at' => $now->format('Y-m-d\TH:i:s\Z'),
            'orders' => [
                'total' => $totals['total'], 'cancelled' => $totals['cancelled'], 'active' => $totals['total'] - $totals['cancelled'],
                'amount_cents' => $totals['amount_cents'], 'shop_total' => $shopOrders,
                'sync_coverage' => $shopOrders > 0 ? min(1.0, $totals['total'] / $shopOrders) : null,
                'by_status' => $bo->orderStatusCounts(),
                'per_day' => $this->fillDays($bo->ordersPerDay(), $now),
            ],
            'top_products' => $bo->topProducts(5),
            'tickets' => $bo->ticketStats(),
            'integration' => [
                'by_status' => $log, 'total' => $logTotal, 'failed' => $failed, 'ok' => $logTotal - $failed,
                'success_rate' => $logTotal > 0 ? ($logTotal - $failed) / $logTotal : null,
                'errors_by_type' => $bo->logErrorsByType(8), 'pending_sync' => $bo->pendingCount(),
            ],
            'funnel' => ['steps' => $funnel, 'conversion' => $viewed > 0 ? min(1.0, $ordered / $viewed) : null],
            'agent' => $agent + ['approval_rate' => $decided > 0 ? $agent['approved'] / $decided : null],
        ];
    }

    /** @return list<array{type: string, label: string, sessions: int}> sesiones distintas que alcanzan cada paso */
    private function funnel(): array
    {
        $counts = [];
        try {
            $in = "'" . implode("','", array_keys(self::FUNNEL)) . "'";
            foreach ($this->shop->query("SELECT type, COUNT(DISTINCT session_id) AS n FROM events WHERE session_id IS NOT NULL AND type IN ($in) GROUP BY type")->fetchAll() as $r) {
                $counts[(string) $r['type']] = (int) $r['n'];
            }
        } catch (Throwable) {
            // BD de la tienda no disponible: el embudo queda a cero en lugar de romper el cuadro de mando.
        }
        $out = [];
        foreach (self::FUNNEL as $type => $label) {
            $out[] = ['type' => $type, 'label' => $label, 'sessions' => $counts[$type] ?? 0];
        }

        return $out;
    }

    private function shopOrderCount(): int
    {
        try {
            return (int) $this->shop->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Serie diaria sin huecos: desde el primer pedido (máx. 30 días atrás) hasta hoy.
     *
     * @param list<array{day: string, orders: int, cents: int}> $rows
     * @return list<array{day: string, orders: int, amount_cents: int}>
     */
    private function fillDays(array $rows, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
        $by = [];
        foreach ($rows as $r) {
            $by[$r['day']] = $r;
        }
        $first = $rows === [] ? $today : new DateTimeImmutable($rows[0]['day'] . ' 00:00:00', new DateTimeZone('UTC'));
        $last = $rows === [] ? $today : new DateTimeImmutable($rows[count($rows) - 1]['day'] . ' 00:00:00', new DateTimeZone('UTC'));
        $start = max($first, $today->modify('-' . (self::CHART_DAYS - 1) . ' days'));
        $end = max($last, $today);
        $out = [];
        for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $out[] = ['day' => $key, 'orders' => $by[$key]['orders'] ?? 0, 'amount_cents' => $by[$key]['cents'] ?? 0];
        }

        return $out;
    }
}
