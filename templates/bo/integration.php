<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$boSection = 'integracion'; include __DIR__ . '/_nav.php';
$states = ['ok', 'duplicate', 'rejected', 'error', 'pending_sync', 'stale', 'skipped'];
$link = static fn (array $o): string => '/bo/integracion?' . http_build_query(array_filter(array_merge(['status' => $status], $o), static fn ($v) => $v !== '' && $v !== null));
?>
<section class="page-heading"><p class="eyebrow">Trazabilidad</p><h1>Registro de integración</h1><p class="muted">Una fila por intento: correcto, duplicado (idempotencia), rechazado por validación, error o pendiente de sincronizar. <?= (int) $total ?> registro(s).</p></section>
<nav class="status-chips"><a class="chip<?= $status === '' ? ' active' : '' ?>" href="/bo/integracion">Todos <strong><?= (int) array_sum($counts) ?></strong></a>
<?php foreach ($states as $s): ?><a class="chip<?= $status === $s ? ' active' : '' ?>" href="/bo/integracion?status=<?= $esc($s) ?>"><?= $esc($s) ?> <strong><?= (int) ($counts[$s] ?? 0) ?></strong></a><?php endforeach; ?></nav>
<?php if ($rows === []): ?><section class="empty-state"><h2>Sin registros</h2><p>Aún no se ha ejecutado ninguna integración.</p></section><?php else: ?>
<div class="table-wrap"><table class="data-table"><thead><tr><th>#</th><th>Fecha (UTC)</th><th>Estado</th><th>Evento</th><th>Entidad</th><th>Workflow</th><th>Código</th><th>Mensaje</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int) $r['id'] ?></td><td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $r['created_at'])) ?></td><td><strong><?= $esc($r['status']) ?></strong></td>
<td><?= $esc($r['event_type'] ?? '—') ?><br><small><?= $esc($r['event_id'] ?? '') ?></small></td><td><?= $esc(trim(($r['entity_type'] ?? '') . ' ' . ($r['entity_ref'] ?? ''))) ?></td>
<td><?= $esc($r['workflow'] ?? '—') ?><br><small><?= $esc($r['execution_id'] ?? '') ?></small></td><td><?= $esc($r['error_code'] ?? '—') ?></td><td><small><?= $esc($r['message'] ?? '') ?></small></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php if ($pages > 1): ?><nav class="pagination"><?php if ($page > 1): ?><a class="button secondary" href="<?= $esc($link(['page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
<span class="muted">Página <?= (int) $page ?> de <?= (int) $pages ?></span><?php if ($page < $pages): ?><a class="button secondary" href="<?= $esc($link(['page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?></nav><?php endif; ?>
<?php endif; ?>
<h2>Pendientes de sincronización</h2>
<?php if ($pending === []): ?><p class="muted">Ninguno.</p><?php else: ?>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Evento</th><th>Tipo</th><th>Pedido</th><th>Motivo</th><th>Estado</th><th>Recibido</th><th>Aplicado</th></tr></thead><tbody>
<?php foreach ($pending as $p): ?><tr><td><small><?= $esc($p['event_id']) ?></small></td><td><?= $esc($p['event_type']) ?></td><td><?= $esc($p['order_code']) ?></td><td><?= $esc($p['reason']) ?></td><td><?= $esc($p['status']) ?></td><td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $p['created_at'])) ?></td><td><?= $esc($p['synced_at'] ? str_replace(['T', 'Z'], [' ', ''], $p['synced_at']) : '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
