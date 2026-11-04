<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', '.') . ' €';
$boSection = 'pedidos'; include __DIR__ . '/_nav.php';
$link = static fn (array $o): string => '/bo/pedidos?' . http_build_query(array_filter(array_merge(['status' => $status, 'q' => $query], $o), static fn ($v) => $v !== '' && $v !== null));
?>
<section class="page-heading"><p class="eyebrow">Back-office</p><h1>Pedidos sincronizados</h1><p class="muted"><?= (int) $total ?> pedido(s).</p></section>
<form class="admin-search" method="get" action="/bo/pedidos">
    <label for="status">Estado</label>
    <select id="status" name="status"><option value="">Todos</option>
        <?php foreach (\App\BackOffice\BoService::ORDER_STATUSES as $s): ?><option value="<?= $esc($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= $esc($s) ?></option><?php endforeach; ?></select>
    <label for="q">Buscar</label><input id="q" type="search" name="q" value="<?= $esc($query) ?>" maxlength="80" placeholder="Código, nombre o email">
    <button class="button secondary" type="submit">Filtrar</button>
</form>
<?php if ($rows === []): ?>
    <section class="empty-state"><h2>Sin pedidos</h2><p>Todavía no ha llegado ningún pedido desde n8n.</p></section>
<?php else: ?>
<div class="table-wrap"><table class="data-table">
    <thead><tr><th>Pedido</th><th>Cliente</th><th>Estado</th><th>Total</th><th>Pago</th><th>Fecha pedido</th><th>Sincronizado</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
        <tr><td><a href="/bo/pedidos/<?= $esc($r['order_code']) ?>"><?= $esc($r['order_code']) ?></a></td>
            <td><?= $esc($r['customer_name']) ?><br><small><?= $esc($r['customer_email']) ?></small></td>
            <td><span class="badge badge-<?= $esc($r['status']) ?>"><?= $esc($r['status']) ?></span></td>
            <td><?= $esc($eur((int) $r['total_cents'])) ?></td><td><?= $esc($r['payment_status'] ?? '—') ?></td>
            <td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $r['ordered_at'])) ?></td><td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $r['synced_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php if ($pages > 1): ?><nav class="pagination">
    <?php if ($page > 1): ?><a class="button secondary" href="<?= $esc($link(['page' => $page - 1])) ?>">← Anterior</a><?php endif; ?>
    <span class="muted">Página <?= (int) $page ?> de <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?><a class="button secondary" href="<?= $esc($link(['page' => $page + 1])) ?>">Siguiente →</a><?php endif; ?></nav><?php endif; ?>
<?php endif; ?>
