<?php
use App\BackOffice\Charts;

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', '.') . ' €';
$pct = static fn (?float $r): string => $r === null ? 'sin datos' : number_format($r * 100, 1, ',', '.') . ' %';
$mins = static function (?float $m): string {
    if ($m === null) { return 'sin datos'; }
    if ($m < 60) { return number_format($m, 1, ',', '.') . ' min'; }
    $h = (int) floor($m / 60);

    return $h . ' h ' . (int) round($m - $h * 60) . ' min';
};
$boSection = 'metricas';
include __DIR__ . '/_nav.php';

$o = $m['orders']; $t = $m['tickets']; $i = $m['integration']; $a = $m['agent']; $f = $m['funnel'];
$days = $o['per_day'];
$funnelItems = [];
foreach ($f['steps'] as $step) { $funnelItems[$step['label']] = $step['sessions']; }
$topItems = [];
foreach ($m['top_products'] as $p) { $topItems[$p['name']] = $p['units']; }
$errItems = [];
foreach ($i['errors_by_type'] as $e) { $errItems[$e['code'] . ' (' . $e['status'] . ')'] = $e['n']; }
$agentStates = ['Pendientes de revisión' => $a['pending'], 'Aprobadas por una persona' => $a['approved'], 'Rechazadas' => $a['rejected']];
?>
<style>
.mx-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.mx-card { background: var(--surface); border: 1px solid var(--line); border-radius: .5rem; padding: 1rem 1.1rem; }
.mx-card h2, .mx-card h3 { margin: 0 0 .35rem; font-size: .95rem; color: var(--muted); font-weight: 700; }
.mx-kpi { font-size: 1.9rem; font-weight: 800; line-height: 1.15; }
.mx-sub { margin: .25rem 0 0; font-size: .85rem; color: var(--muted); }
.mx-charts { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.mx-wide { grid-column: 1 / -1; }
.mx-card details { margin-top: .6rem; font-size: .88rem; }
.mx-card table { width: 100%; border-collapse: collapse; margin-top: .4rem; }
.mx-card th, .mx-card td { padding: .2rem .4rem; border-bottom: 1px solid var(--line); text-align: left; }
</style>

<section class="page-heading">
    <p class="eyebrow">Cuadro de mando operativo</p>
    <h1>Métricas del proceso integrado</h1>
    <p class="muted">Todas las cifras se calculan <strong>en este momento</strong> a partir de datos generados por el propio sistema: eventos y pedidos de la tienda, y pedidos, tickets, registro de integración y propuestas del agente del back-office. No hay datos escritos a mano. Generado: <?= $esc(str_replace(['T', 'Z'], [' ', ' UTC'], $m['generated_at'])) ?> ·
        <a href="/bo/metricas/datos">ver en JSON</a>.</p>
</section>

<div class="mx-grid">
    <section class="mx-card"><h2>Número de pedidos (back-office)</h2><p class="mx-kpi"><?= (int) $o['total'] ?></p>
        <p class="mx-sub"><?= (int) $o['active'] ?> activos · <?= (int) $o['cancelled'] ?> cancelados · en la tienda: <?= (int) $o['shop_total'] ?> · sincronizados: <?= $esc($pct($o['sync_coverage'])) ?></p></section>
    <section class="mx-card"><h2>Importe total simulado</h2><p class="mx-kpi"><?= $esc($eur((int) $o['amount_cents'])) ?></p><p class="mx-sub">IVA incluido, sin pedidos cancelados. Importes ficticios.</p></section>
    <section class="mx-card"><h2>Incidencias abiertas</h2><p class="mx-kpi"><?= (int) $t['open'] + (int) $t['in_progress'] ?></p>
        <p class="mx-sub"><?= (int) $t['open'] ?> abiertas · <?= (int) $t['in_progress'] ?> en curso · <?= (int) $t['resolved'] ?> resueltas · <?= (int) $t['total'] ?> en total</p></section>
    <section class="mx-card"><h2>Tiempo medio simulado de resolución</h2><p class="mx-kpi"><?= $esc($mins($t['avg_minutes'])) ?></p><p class="mx-sub">Media entre creación y resolución de los tickets resueltos (<?= (int) $t['resolved'] ?>).</p></section>
    <section class="mx-card"><h2>Eventos de integración correctos / fallidos</h2><p class="mx-kpi"><?= (int) $i['ok'] ?> / <?= (int) $i['failed'] ?></p>
        <p class="mx-sub">Tasa de éxito: <?= $esc($pct($i['success_rate'])) ?> · pendientes de sincronizar: <?= (int) $i['pending_sync'] ?></p></section>
    <section class="mx-card"><h2>Tasa de conversión del flujo</h2><p class="mx-kpi"><?= $esc($pct($f['conversion'])) ?></p><p class="mx-sub">Sesiones que acaban en pedido / sesiones que ven un producto.</p></section>
    <section class="mx-card"><h2>Acciones propuestas por el agente</h2><p class="mx-kpi"><?= (int) $a['total'] ?></p>
        <p class="mx-sub"><?= (int) $a['approved'] ?> aprobadas · <?= (int) $a['rejected'] ?> rechazadas · <?= (int) $a['pending'] ?> pendientes · aprobación: <?= $esc($pct($a['approval_rate'])) ?></p></section>
    <section class="mx-card"><h2>Confianza media del agente</h2><p class="mx-kpi"><?= $a['avg_confidence'] === null ? 'sin datos' : $esc(number_format((float) $a['avg_confidence'], 2, ',', '')) ?></p>
        <p class="mx-sub">Sin contar el modo seguro (<?= (int) $a['failsafe'] ?> propuesta(s) con confianza 0).</p></section>
</div>

<div class="mx-charts">
    <section class="mx-card mx-wide">
        <h3>Histórico del número de pedidos por día (back-office)</h3>
        <?= Charts::verticalBars(array_column($days, 'day'), array_column($days, 'orders'), 'Pedidos por día', 'pedidos') ?>
        <details><summary>Ver datos de la gráfica</summary>
            <table><thead><tr><th>Día</th><th>Pedidos</th><th>Importe</th></tr></thead><tbody>
            <?php foreach ($days as $d): ?><tr><td><?= $esc($d['day']) ?></td><td><?= (int) $d['orders'] ?></td><td><?= $esc($eur((int) $d['amount_cents'])) ?></td></tr><?php endforeach; ?>
            </tbody></table></details>
    </section>
    <section class="mx-card"><h3>Pedidos por estado</h3><?= Charts::horizontalBars($o['by_status'], 'Pedidos por estado') ?></section>
    <section class="mx-card"><h3>Productos más solicitados (unidades)</h3>
        <?= $topItems === [] ? '<p class="mx-sub">Todavía no hay pedidos sincronizados.</p>' : Charts::horizontalBars($topItems, 'Productos más solicitados', '#e2553f') ?></section>
    <section class="mx-card"><h3>Embudo de conversión (sesiones por paso)</h3><?= Charts::horizontalBars($funnelItems, 'Embudo de conversión', '#2d8a56') ?>
        <p class="mx-sub">Fuente: tabla <code>events</code> de la tienda (<code>product.viewed</code> → <code>cart.item_added</code> → <code>checkout.started</code> → <code>order.created</code>).</p></section>
    <section class="mx-card"><h3>Registro de integración por resultado</h3><?= Charts::horizontalBars($i['by_status'], 'Eventos de integración por resultado', '#006d77') ?></section>
    <section class="mx-card"><h3>Errores por tipo</h3>
        <?= $errItems === [] ? '<p class="mx-sub">Sin errores ni rechazos registrados.</p>' : Charts::horizontalBars($errItems, 'Errores por tipo', '#8a2618') ?></section>
    <section class="mx-card"><h3>Agente de IA: propuestas por estado</h3><?= Charts::horizontalBars($agentStates, 'Propuestas del agente por estado', '#7a5600') ?>
        <?php if ($a['by_action'] !== []): ?><details><summary>Por tipo de acción</summary><table><tbody>
            <?php foreach ($a['by_action'] as $k => $n): ?><tr><td><?= $esc($k) ?></td><td><?= (int) $n ?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?></section>
</div>

<section class="mx-card">
    <h2>¿Por qué estas métricas?</h2>
    <p class="mx-sub">Cada indicador responde a una pregunta operativa del proceso y se apoya en prácticas publicadas de gestión de servicios, observabilidad, integración de sistemas y gobierno de la IA. La justificación completa, con las referencias, está en <code>docs/metricas-justificacion.md</code>.</p>
    <details><summary>Resumen</summary>
        <ul>
            <li><strong>Pedidos, importe, productos y estados:</strong> volumen y estado del proceso de venta (indicadores básicos de comercio electrónico).</li>
            <li><strong>Conversión y embudo:</strong> eficacia del flujo de compra, por pasos (análisis de embudos de comercio electrónico).</li>
            <li><strong>Incidencias abiertas y tiempo medio de resolución:</strong> gestión de incidencias (ITIL 4) y su métrica clásica, el tiempo medio de resolución.</li>
            <li><strong>Eventos correctos/fallidos y errores por tipo:</strong> tráfico y errores de un sistema distribuido (SRE, «señales doradas») y patrones de mensajería fiable (receptor idempotente, canal de mensajes fallidos).</li>
            <li><strong>Propuestas del agente y aprobadas:</strong> medida de la supervisión humana de un sistema de IA (NIST AI RMF; Reglamento (UE) 2024/1689, art. 14).</li>
        </ul>
    </details>
</section>
