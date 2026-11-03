<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', '.') . ' €';
$boSection = 'pedidos'; include __DIR__ . '/_nav.php';
?>
<section class="page-heading"><p class="eyebrow">Pedido en el back-office</p><h1><?= $esc($o['order_code']) ?> <span class="badge badge-<?= $esc($o['status']) ?>"><?= $esc($o['status']) ?></span></h1>
    <p class="muted">Cliente: <?= $esc($o['customer_name']) ?> (<?= $esc($o['customer_email']) ?>) · Pedido del <?= $esc(str_replace(['T', 'Z'], [' ', ''], $o['ordered_at'])) ?> UTC · Último estado: <?= $esc(str_replace(['T', 'Z'], [' ', ''], $o['status_updated_at'])) ?> · Último evento aplicado: <code><?= $esc($o['last_event_id'] ?? '—') ?></code></p></section>
<div class="table-wrap"><table class="data-table"><thead><tr><th>SKU</th><th>Producto</th><th>Talla</th><th>Cant.</th><th>Precio ud.</th></tr></thead><tbody>
<?php foreach ($lines as $l): ?><tr><td><?= $esc($l['sku']) ?></td><td><?= $esc($l['name']) ?></td><td><?= (int) $l['size_eu'] ?></td><td><?= (int) $l['quantity'] ?></td><td><?= $esc($eur((int) $l['unit_price_cents'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<p>Subtotal <?= $esc($eur((int) $o['subtotal_cents'])) ?> · Descuento −<?= $esc($eur((int) $o['discount_cents'])) ?> · Envío <?= $esc($eur((int) $o['shipping_cents'])) ?> · IVA incluido <?= $esc($eur((int) $o['tax_cents'])) ?> · <strong>Total <?= $esc($eur((int) $o['total_cents'])) ?></strong> · Pago: <?= $esc($o['payment_status'] ?? '—') ?> (<?= $esc($o['payment_reference'] ?? '—') ?>)</p>
<h2>Tickets asociados</h2>
<?php if ($tickets === []): ?><p class="muted">Sin tickets.</p><?php else: ?>
<div class="table-wrap"><table class="data-table"><thead><tr><th>Ref.</th><th>Tipo</th><th>Asunto</th><th>Prioridad</th><th>Estado</th><th>Creado</th></tr></thead><tbody>
<?php foreach ($tickets as $t): ?><tr><td><?= $esc($t['ticket_ref']) ?></td><td><?= $esc($t['kind']) ?></td><td><?= $esc($t['subject']) ?><br><small><?= $esc(mb_strimwidth((string) $t['message'], 0, 140, '…')) ?></small></td><td><?= $esc($t['priority']) ?></td><td><?= $esc($t['status']) ?></td><td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $t['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
