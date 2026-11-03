<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$boSection = 'tickets'; include __DIR__ . '/_nav.php';
$flashOk = $_SESSION['_flash_success'] ?? null; $flashErr = $_SESSION['_flash_error'] ?? null;
unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);
?>
<section class="page-heading"><p class="eyebrow">Back-office</p><h1>Tickets de soporte e incidencias</h1>
    <p class="muted">Al resolver un ticket se guarda la fecha de resolución: de ahí sale el tiempo medio de resolución del <a href="/bo/metricas">cuadro de mando</a>.</p></section>
<?php if ($flashOk): ?><p class="notice success" role="status"><?= $esc($flashOk) ?></p><?php endif; ?>
<?php if ($flashErr): ?><p class="notice error" role="alert"><?= $esc($flashErr) ?></p><?php endif; ?>
<nav class="status-chips" aria-label="Filtrar por estado">
    <a class="chip<?= $status === '' ? ' active' : '' ?>" href="/bo/tickets">Todos <strong><?= (int) array_sum($counts) ?></strong></a>
    <?php foreach (\App\BackOffice\BoService::TICKET_STATUSES as $s): ?><a class="chip<?= $status === $s ? ' active' : '' ?>" href="/bo/tickets?status=<?= $esc($s) ?>"><?= $esc($s) ?> <strong><?= (int) ($counts[$s] ?? 0) ?></strong></a><?php endforeach; ?>
</nav>
<?php if ($rows === []): ?><section class="empty-state"><h2>Sin tickets</h2><p>No hay tickets con este filtro.</p></section><?php else: ?>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Ref.</th><th>Tipo</th><th>Pedido</th><th>Cliente</th><th>Asunto</th><th>Prioridad</th><th>Estado</th><th>Creado</th><th>Resuelto</th><th>Acción</th></tr></thead><tbody>
<?php foreach ($rows as $t): ?><tr><td><?= $esc($t['ticket_ref']) ?></td><td><?= $esc($t['kind']) ?></td><td><a href="/bo/pedidos/<?= $esc($t['order_code']) ?>"><?= $esc($t['order_code']) ?></a></td><td><?= $esc($t['customer_name']) ?></td>
<td><?= $esc($t['subject']) ?><br><small><?= $esc(mb_strimwidth((string) $t['message'], 0, 120, '…')) ?></small></td><td><?= $esc($t['priority']) ?></td><td><?= $esc($t['status']) ?></td>
<td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $t['created_at'])) ?></td><td><?= $esc($t['resolved_at'] ? str_replace(['T', 'Z'], [' ', ''], $t['resolved_at']) : '—') ?></td>
<td><form method="post" action="/bo/tickets/<?= $esc($t['ticket_ref']) ?>/estado" style="display:flex;gap:.3rem;flex-wrap:wrap"><input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
<?php if ($t['status'] === 'open'): ?><button class="button secondary" type="submit" name="status" value="in_progress">Iniciar</button><?php endif; ?>
<?php if ($t['status'] !== 'resolved'): ?><button class="button" type="submit" name="status" value="resolved">Resolver</button><?php else: ?><button class="button secondary" type="submit" name="status" value="open">Reabrir</button><?php endif; ?></form></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
