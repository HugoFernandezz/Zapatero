<?php

// Test del cuadro de mando:  composer test   (o: php tests/metrics.php)
// Comprueba que cada métrica se calcula a partir de datos reales del sistema y que los gráficos escapan el texto.

declare(strict_types=1);

use App\BackOffice\BoDatabase;
use App\BackOffice\BoRepository;
use App\BackOffice\BoService;
use App\BackOffice\Charts;
use App\BackOffice\MetricsService;
use App\Database;
use App\Services\EventService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/zapatero-metrics-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$shop = Database::connect($tmp . '/shop.sqlite');
$boPdo = BoDatabase::connect($tmp . '/bo.sqlite');
$svc = new BoService(new BoRepository($boPdo));
$metrics = new MetricsService($shop, static fn () => $svc);

$failures = 0;
$check = static function (bool $ok, string $msg) use (&$failures): void {
    echo ($ok ? '  OK    ' : '  FALLO ') . $msg . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};
$eid = static fn (int $n): string => sprintf('evt_%032x', $n);
$order = static fn (string $code, string $status, string $day, int $total, array $items): array => [
    'order_code' => $code, 'status' => $status, 'created_at' => $day . 'T10:00:00Z', 'status_updated_at' => $day . 'T10:00:00Z', 'currency' => 'EUR',
    'subtotal_cents' => $total, 'discount_cents' => 0, 'shipping_cents' => 0, 'tax_cents' => 0, 'total_cents' => $total,
    'customer' => ['full_name' => 'Cliente Prueba', 'email' => 'cliente@zapatero.test', 'city' => 'Murcia', 'province' => 'Murcia'],
    'items' => $items, 'payment' => ['method' => 'card_simulated', 'status' => 'approved', 'reference' => 'SIM-0000000001'],
];
$line = static fn (string $name, int $qty): array => ['sku' => 'SKU-' . substr(md5($name), 0, 4), 'name' => $name, 'size_eu' => 42, 'quantity' => $qty, 'unit_price_cents' => 10000];

echo "Sin datos (todo a cero, sin errores)\n";
$empty = $metrics->snapshot(new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC')));
$check($empty['orders']['total'] === 0 && $empty['orders']['amount_cents'] === 0, 'pedidos e importe a cero');
$check($empty['tickets']['avg_minutes'] === null && $empty['funnel']['conversion'] === null && $empty['agent']['approval_rate'] === null, 'las razones sin datos son null (no 0 inventado)');
$check(count($empty['orders']['per_day']) === 1, 'la serie diaria sin pedidos tiene solo el día de hoy');

echo "Datos del sistema\n";
$svc->ingestOrder(['event_id' => $eid(1), 'event_type' => 'order.created', 'order' => $order('ZAP-20261030-0001', 'paid_simulated', '2026-10-30', 10000, [$line('Arcade Runner', 2), $line('Nube', 1)])]);
$svc->ingestOrder(['event_id' => $eid(2), 'event_type' => 'order.created', 'order' => $order('ZAP-20261030-0002', 'shipped', '2026-10-30', 25000, [$line('Arcade Runner', 1)])]);
$svc->ingestOrder(['event_id' => $eid(3), 'event_type' => 'order.created', 'order' => $order('ZAP-20261101-0001', 'cancelled', '2026-11-01', 7000, [$line('Nube', 5)])]);
try {
    $svc->ingestOrder(['event_id' => $eid(4), 'event_type' => 'order.created', 'order' => ['order_code' => 'MAL', 'customer' => []]]);
} catch (\App\BackOffice\BoException) {
    // rechazo esperado: queda como error por tipo (validation_failed)
}
$svc->ingestOrder(['event_id' => $eid(1), 'event_type' => 'order.created', 'order' => $order('ZAP-20261030-0001', 'paid_simulated', '2026-10-30', 10000, [$line('Arcade Runner', 2)])]);   // duplicado

// Tickets: uno resuelto tras 2 h, otro abierto, otro en curso
$twoHoursAgo = gmdate('Y-m-d\TH:i:s\Z', time() - 7200);
$svc->ingestTicket(['event_id' => $eid(10), 'event_type' => 'support.requested', 'ticket' => ['ticket_ref' => 'SUP-1', 'kind' => 'support', 'order_code' => 'ZAP-20261030-0001', 'subject' => 'Talla', 'message' => 'Me queda pequeña.', 'created_at' => $twoHoursAgo]]);
$svc->ingestTicket(['event_id' => $eid(11), 'event_type' => 'incident.created', 'ticket' => ['ticket_ref' => 'INC-aaaa1111', 'kind' => 'incident', 'order_code' => 'ZAP-20261030-0002', 'subject' => 'Incidencia', 'message' => 'Caja rota']]);
$svc->ingestTicket(['event_id' => $eid(12), 'event_type' => 'support.requested', 'ticket' => ['ticket_ref' => 'SUP-3', 'kind' => 'support', 'order_code' => 'ZAP-20261030-0002', 'subject' => 'Duda', 'message' => 'Una duda.']]);
$svc->updateTicket('SUP-1', ['status' => 'resolved']);
$svc->updateTicket('SUP-3', ['status' => 'in_progress']);

// Agente: 1 aprobada, 1 rechazada, 1 pendiente y 1 en modo seguro
$base = ['ticket_ref' => 'SUP-1', 'order_code' => 'ZAP-20261030-0001', 'case_type' => 'size_change', 'consulted_data' => ['consultar_pedido'],
    'action' => ['type' => 'customer_message', 'message' => 'Hola, recibido.'], 'justification' => 'Pide cambio de talla en un pedido pagado.', 'confidence' => 0.8, 'priority' => 'normal', 'risks' => []];
$p1 = $svc->proposeAgentAction($base);
$p2 = $svc->proposeAgentAction($base);
$svc->proposeAgentAction($base);
$svc->proposeAgentAction(['action' => ['type' => 'internal_task', 'task' => 'Revisar a mano'], 'confidence' => 0.0, 'priority' => 'high'] + $base);
$svc->decide($p1['id'], 'approve', 'admin@zapatero.test', '');
$svc->decide($p2['id'], 'reject', 'admin@zapatero.test', '');

// Eventos de la tienda (embudo): 3 sesiones ven producto, 2 añaden, 1 inicia checkout, 1 compra
$ev = new EventService($shop);
foreach (['s1', 's2', 's3'] as $s) { $ev->record('product.viewed', ['product_id' => 1], $s); }
foreach (['s1', 's2'] as $s) { $ev->record('cart.item_added', ['product_id' => 1], $s); }
$ev->record('cart.item_added', ['product_id' => 2], 's1');            // misma sesión: cuenta una vez
$ev->record('checkout.started', [], 's1');
$ev->record('order.created', ['order_code' => 'ZAP-20261030-0001'], 's1');

$m = $metrics->snapshot(new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC')));

echo "Pedidos\n";
$check($m['orders']['total'] === 3 && $m['orders']['cancelled'] === 1 && $m['orders']['active'] === 2, 'número de pedidos: 3 (1 cancelado, 2 activos)');
$check($m['orders']['amount_cents'] === 35000, 'importe total simulado sin cancelados: 350,00 €');
$st = $m['orders']['by_status'];
$check(($st['cancelled'] ?? 0) === 1 && ($st['paid_simulated'] ?? 0) === 1 && ($st['shipped'] ?? 0) === 1, 'pedidos por estado: 1 cancelado, 1 pagado, 1 enviado');
$days = array_column($m['orders']['per_day'], 'orders', 'day');
$check(array_keys($days) === ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], 'serie diaria sin huecos desde el primer pedido hasta hoy');
$check($days['2026-10-30'] === 2 && $days['2026-10-31'] === 0 && $days['2026-11-01'] === 1, 'pedidos por día correctos (2, 0, 1)');
$check($m['top_products'][0] === ['name' => 'Arcade Runner', 'units' => 3], 'producto más solicitado: Arcade Runner (3 uds), sin contar cancelados');
$check(($m['top_products'][1] ?? null) === ['name' => 'Nube', 'units' => 1], 'las 5 unidades de «Nube» del pedido cancelado no cuentan (solo 1)');

echo "Tickets\n";
$check($m['tickets']['total'] === 3 && $m['tickets']['open'] === 1 && $m['tickets']['in_progress'] === 1 && $m['tickets']['resolved'] === 1, 'tickets: 1 abierto, 1 en curso, 1 resuelto');
$check($m['tickets']['avg_minutes'] !== null && $m['tickets']['avg_minutes'] > 119 && $m['tickets']['avg_minutes'] < 122, 'tiempo medio de resolución ≈ 120 min (' . round((float) $m['tickets']['avg_minutes'], 1) . ')');

echo "Integración\n";
$check($m['integration']['failed'] >= 1, 'el rechazo por validación cuenta como evento fallido');
$check(($m['integration']['by_status']['duplicate'] ?? 0) === 1, 'el duplicado se cuenta como duplicate');
$check($m['integration']['ok'] + $m['integration']['failed'] === $m['integration']['total'], 'correctos + fallidos = total del registro');
$check($m['integration']['errors_by_type'][0]['code'] === 'validation_failed', 'errores por tipo: validation_failed');
$rate = $m['integration']['success_rate'];
$check($rate !== null && $rate > 0 && $rate < 1, 'tasa de éxito entre 0 y 1');

echo "Embudo\n";
$steps = array_column($m['funnel']['steps'], 'sessions', 'type');
$check($steps === ['product.viewed' => 3, 'cart.item_added' => 2, 'checkout.started' => 1, 'order.created' => 1], 'sesiones distintas por paso (3 → 2 → 1 → 1)');
$check(abs((float) $m['funnel']['conversion'] - 1 / 3) < 0.0001, 'conversión = 1 / 3');

echo "Agente\n";
$check($m['agent']['total'] === 4 && $m['agent']['approved'] === 1 && $m['agent']['rejected'] === 1 && $m['agent']['pending'] === 2, 'propuestas: 4 (1 aprobada, 1 rechazada, 2 pendientes)');
$check($m['agent']['approval_rate'] === 0.5, 'tasa de aprobación sobre las decididas = 50 %');
$check($m['agent']['failsafe'] === 1 && abs((float) $m['agent']['avg_confidence'] - 0.8) < 0.0001, 'el modo seguro (confianza 0) no distorsiona la confianza media');

echo "Gráficos\n";
$svg = Charts::horizontalBars(['<script>alert(1)</script>' => 3, 'Normal' => 1], 'Prueba "x"');
$check(str_starts_with($svg, '<svg') && !str_contains($svg, '<script>') && str_contains($svg, 'aria-label'), 'SVG accesible y con el texto escapado (sin inyección)');
$check(str_contains(Charts::verticalBars(['2026-10-30', '2026-10-31'], [2, 0], 'Pedidos por día'), '<rect'), 'barras verticales generadas');
$check(str_starts_with(Charts::horizontalBars([], 'vacío'), '<svg') && str_starts_with(Charts::verticalBars([], [], 'vacío'), '<svg'), 'gráficos vacíos no fallan');

unset($metrics, $svc, $shop, $boPdo, $ev);
gc_collect_cycles();
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo $failures === 0 ? "\nTodo correcto.\n" : "\n$failures comprobación(es) fallida(s).\n";
exit($failures === 0 ? 0 : 1);
