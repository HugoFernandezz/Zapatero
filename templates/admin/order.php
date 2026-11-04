<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => \App\Support\Format::money($cents);
$o = $detail['order'];
$c = $detail['customer'];
$p = $detail['payment'];
$paid = ($p['status'] ?? '') === 'approved';
$label = static fn (string $s): string => \App\Support\OrderStatus::label($s);
?>
<?php $adminSection = 'pedidos'; include __DIR__ . '/_nav.php'; ?>
<section class="page-heading admin-heading">
    <div>
        <p class="eyebrow"><a class="text-link" href="/admin/pedidos">← Todos los pedidos</a></p>
        <h1>Pedido <span class="order-code"><?= $esc($o['code']) ?></span></h1>
        <p><span class="badge badge-<?= $esc($o['status']) ?>"><?= $esc($label((string) $o['status'])) ?></span> · <?= $esc(\App\Support\Format::date($o['created_at'])) ?></p>
    </div>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<section class="admin-grid">
    <div class="summary-card">
        <h2>Cambiar estado</h2>
        <?php if ($nextStatuses === []): ?>
            <p class="muted">«<?= $esc($label((string) $o['status'])) ?>» es un estado final: no admite más cambios.</p>
        <?php else: ?>
            <form method="post" action="/admin/pedidos/<?= rawurlencode((string) $o['code']) ?>/estado" class="status-form">
                <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
                <div class="form-field">
                    <label for="status">Nuevo estado</label>
                    <select id="status" name="status" required>
                        <?php foreach ($nextStatuses as $s): ?><option value="<?= $esc($s) ?>"><?= $esc($label($s)) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label for="note">Nota / motivo (opcional, útil en incidencias)</label>
                    <input id="note" name="note" maxlength="200">
                </div>
                <button class="button primary" type="submit">Actualizar estado</button>
            </form>
            <p class="muted">Cancelar un pedido ya cobrado devuelve las unidades al stock.</p>
        <?php endif; ?>

        <?php if ($paid): ?>
            <hr>
            <form method="post" action="/admin/pedidos/<?= rawurlencode((string) $o['code']) ?>/factura">
                <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
                <button class="button secondary" type="submit">Reenviar factura por email</button>
                <a class="text-link" href="/pedido/<?= rawurlencode((string) $o['code']) ?>/factura">Ver factura</a>
            </form>
        <?php endif; ?>
    </div>

    <div class="summary-card">
        <h2>Cliente y pago</h2>
        <p class="muted">
            <?= $esc($c['full_name'] ?? '') ?><br>
            <?= $esc($c['email'] ?? '') ?><br>
            <?= $esc($c['address'] ?? '') ?>, <?= $esc($c['postal_code'] ?? '') ?> <?= $esc($c['city'] ?? '') ?> (<?= $esc($c['province'] ?? '') ?>)
        </p>
        <?php if ($p !== null): ?>
            <p class="muted">
                Pago simulado: <strong><?= $paid ? 'aprobado' : 'rechazado' ?></strong><?= !empty($p['card_last4']) ? ' · tarjeta •••• ' . $esc($p['card_last4']) : '' ?><br>
                Ref. <?= $esc($p['reference']) ?> · <?= $esc(\App\Support\Format::date($p['created_at'])) ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<section class="admin-section">
    <h2>Líneas del pedido</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Producto</th><th>Talla</th><th>SKU</th><th>Cant.</th><th>Precio</th><th>Importe</th></tr></thead>
            <tbody>
                <?php foreach ($detail['items'] as $item): ?>
                    <tr>
                        <td><?= $esc($item['name']) ?></td>
                        <td>EU <?= (int) $item['size_eu'] ?></td>
                        <td><code><?= $esc($item['sku']) ?></code></td>
                        <td><?= (int) $item['quantity'] ?></td>
                        <td><?= $money((int) $item['unit_price_cents']) ?></td>
                        <td><?= $money((int) $item['unit_price_cents'] * (int) $item['quantity']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <dl class="totals admin-totals">
        <div><dt>Subtotal</dt><dd><?= $money((int) $o['subtotal_cents']) ?></dd></div>
        <?php if ((int) $o['discount_cents'] > 0): ?><div><dt>Descuento <?= $esc($o['discount_code'] ?? '') ?></dt><dd>−<?= $money((int) $o['discount_cents']) ?></dd></div><?php endif; ?>
        <div><dt>Envío</dt><dd><?= (int) $o['shipping_cents'] === 0 ? 'Gratis' : $money((int) $o['shipping_cents']) ?></dd></div>
        <div><dt>IVA incluido (21 %)</dt><dd><?= $money((int) $o['tax_cents']) ?></dd></div>
        <div class="grand-total"><dt>Total</dt><dd><?= $money((int) $o['total_cents']) ?></dd></div>
    </dl>
</section>

<section class="admin-section">
    <h2>Solicitudes de soporte</h2>
    <?php if (empty($detail['tickets'])): ?>
        <p class="muted">El cliente no ha enviado ninguna solicitud.</p>
    <?php else: ?>
        <ul class="support-list">
            <?php foreach ($detail['tickets'] as $ticket): ?>
                <li>
                    <strong><?= $esc($ticket['subject']) ?></strong>
                    <small class="muted">· n.º <?= (int) $ticket['id'] ?> · <?= $esc(\App\Support\Format::date($ticket['created_at'])) ?> · <?= $ticket['status'] === 'open' ? 'abierta' : $esc($ticket['status']) ?></small>
                    <p><?= nl2br($esc($ticket['message'])) ?></p>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="admin-section">
    <h2>Historial de eventos</h2>
    <?php if ($detail['events'] === []): ?>
        <p class="muted">Sin eventos registrados.</p>
    <?php else: ?>
        <ol class="timeline">
            <?php foreach ($detail['events'] as $event): ?>
                <?php $data = json_decode((string) $event['payload'], true); $data = is_array($data) ? $data : []; ?>
                <li>
                    <time><?= $esc(\App\Support\Format::date($event['occurred_at'])) ?></time>
                    <strong><?= $esc($event['type']) ?></strong>
                    <?php if ($event['type'] === 'order.status_changed'): ?>
                        <span><?= $esc($label((string) ($data['from'] ?? ''))) ?> → <?= $esc($label((string) ($data['to'] ?? ''))) ?> · por <?= $esc($data['by'] ?? '') ?><?= !empty($data['note']) ? ' · «' . $esc($data['note']) . '»' : '' ?></span>
                    <?php elseif ($event['type'] === 'incident.created'): ?>
                        <span><?= !empty($data['motivo']) ? $esc($data['motivo']) : 'Sin motivo indicado' ?></span>
                    <?php elseif ($event['type'] === 'support.requested'): ?>
                        <span><?= $esc($data['subject'] ?? '') ?> · solicitud n.º <?= (int) ($data['ticket_id'] ?? 0) ?></span>
                    <?php elseif ($event['type'] === 'payment.simulated'): ?>
                        <span><?= $esc($data['result'] ?? '') ?> · <?= $esc($data['reference'] ?? '') ?></span>
                    <?php elseif ((str_starts_with((string) $event['type'], 'invoice.') || str_starts_with((string) $event['type'], 'status_email.'))): ?>
                        <span><?= $esc($data['to'] ?? '') ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
