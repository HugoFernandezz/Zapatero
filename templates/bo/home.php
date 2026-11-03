<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$boSection = 'inicio'; include __DIR__ . '/_nav.php';
$sum = static fn (array $a): int => (int) array_sum($a);
?>
<section class="page-heading">
    <p class="eyebrow">Back-office de integración</p>
    <h1>Resumen</h1>
    <p class="muted">Sistema interno separado de la tienda, con su propia base de datos. Recibe pedidos, cambios de estado e incidencias a través de n8n. Datos ficticios.</p>
</section>
<div class="admin-grid">
    <section class="summary-card" style="position:static"><h2>Pedidos sincronizados</h2><p><strong><?= $sum($orders) ?></strong></p>
        <?php foreach ($orders as $k => $n): ?><span class="badge badge-<?= $esc($k) ?>"><?= $esc($k) ?> <?= (int) $n ?></span> <?php endforeach; ?></section>
    <section class="summary-card" style="position:static"><h2>Tickets</h2><p><strong><?= $sum($tickets) ?></strong> (abiertos: <?= (int) ($tickets['open'] ?? 0) ?>)</p><a class="text-link" href="/bo/tickets">Ver tickets</a></section>
    <section class="summary-card" style="position:static"><h2>Agente: pendientes de revisión</h2><p><strong><?= (int) ($agent['pending_review'] ?? 0) ?></strong></p><a class="text-link" href="/bo/agente?status=pending_review">Revisar propuestas</a></section>
    <section class="summary-card" style="position:static"><h2>Integración</h2><p>Correctas: <strong><?= (int) ($log['ok'] ?? 0) ?></strong> · Fallidas: <strong><?= (int) ($log['rejected'] ?? 0) + (int) ($log['error'] ?? 0) ?></strong> · Pendientes de sincronizar: <strong><?= (int) $pending ?></strong></p><a class="text-link" href="/bo/integracion">Ver registro</a></section>
    <section class="summary-card" style="position:static"><h2>Cuadro de mando</h2><p>Indicadores y gráficos calculados con datos reales.</p><a class="text-link" href="/bo/metricas">Abrir métricas</a></section>
</div>
