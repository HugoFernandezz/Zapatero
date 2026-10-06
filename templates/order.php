<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => \App\Support\Format::money($cents);
$o = $detail['order'];
$c = $detail['customer'];
$p = $detail['payment'];
$paid = ($p['status'] ?? '') === 'approved';
$timeline = \App\Services\OrderService::statusTimeline($detail['events']);
$statusUrl = '/pedido/' . rawurlencode((string) $o['code']) . '/estado';
?>
<section class="page-heading">
    <p class="eyebrow"><?= $paid ? '¡Gracias por tu compra!' : 'Pedido no completado' ?></p>
    <h1>Pedido <span class="order-code"><?= $esc($o['code']) ?></span></h1>
    <p><span id="order-status-badge" class="badge badge-<?= $esc($o['status']) ?>"><?= $esc(\App\Support\OrderStatus::label((string) $o['status'])) ?></span>
        · <?= $esc(\App\Support\Format::date($o['created_at'])) ?></p>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<section class="checkout-layout">
    <div class="summary-card">
        <h2>Artículos</h2>
        <?php foreach ($detail['items'] as $item): ?>
            <div class="checkout-item"><span><?= $esc($item['name']) ?> · EU <?= (int) $item['size_eu'] ?> × <?= (int) $item['quantity'] ?></span><strong><?= $money((int) $item['unit_price_cents'] * (int) $item['quantity']) ?></strong></div>
        <?php endforeach; ?>
        <dl class="totals">
            <div><dt>Subtotal</dt><dd><?= $money((int) $o['subtotal_cents']) ?></dd></div>
            <?php if ((int) $o['discount_cents'] > 0): ?><div><dt>Descuento <?= $esc($o['discount_code'] ?? '') ?></dt><dd>−<?= $money((int) $o['discount_cents']) ?></dd></div><?php endif; ?>
            <div><dt>Envío</dt><dd><?= (int) $o['shipping_cents'] === 0 ? 'Gratis' : $money((int) $o['shipping_cents']) ?></dd></div>
            <div><dt>IVA incluido (21 %)</dt><dd><?= $money((int) $o['tax_cents']) ?></dd></div>
            <div class="grand-total"><dt>Total</dt><dd><?= $money((int) $o['total_cents']) ?></dd></div>
        </dl>
    </div>

    <aside class="summary-card">
        <h2>Detalles</h2>
        <p class="muted">
            <strong>Envío a</strong><br>
            <?= $esc($c['full_name'] ?? '') ?><br>
            <?= $esc($c['address'] ?? '') ?><br>
            <?= $esc($c['postal_code'] ?? '') ?> <?= $esc($c['city'] ?? '') ?> (<?= $esc($c['province'] ?? '') ?>)
        </p>
        <?php if ($p !== null): ?>
            <p class="muted">
                <strong>Pago</strong><br>
                Tarjeta de prueba<?= !empty($p['card_last4']) ? ' · •••• ' . $esc($p['card_last4']) : '' ?> ·
                <?= $paid ? 'aprobado' : 'rechazado' ?><br>
                Ref. <?= $esc($p['reference']) ?>
            </p>
        <?php endif; ?>
        <?php if ($paid): ?>
            <p class="muted">Factura enviada al email de contacto: <strong><?= $esc($c['email'] ?? '') ?></strong>.</p>
            <a class="button primary full-width" href="/pedido/<?= rawurlencode((string) $o['code']) ?>/factura">Ver factura</a>
        <?php endif; ?>
        <a class="text-link" href="/catalogo">Seguir comprando</a>
    </aside>
</section>

<section class="summary-card tracking" aria-label="Seguimiento del pedido" data-status-url="<?= $esc($statusUrl) ?>">
    <h2>Seguimiento del pedido</h2>
    <p class="muted">Estado actual: <strong id="tracking-current"><?= $esc(\App\Support\OrderStatus::label((string) $o['status'])) ?></strong>. Esta página se actualiza sola cuando cambia el estado.</p>
    <ol id="tracking-list" class="tracking-list">
        <?php foreach ($timeline as $step): ?>
            <li><span class="badge badge-<?= $esc($step['status']) ?>"><?= $esc(\App\Support\OrderStatus::label($step['status'])) ?></span> <small class="muted"><?= $esc(\App\Support\Format::date($step['at'])) ?></small></li>
        <?php endforeach; ?>
    </ol>
    <?php if (!empty($_SESSION['user_id'])): ?><a class="text-link" href="/mis-pedidos">← Mis pedidos</a><?php endif; ?>
</section>

<?php if ($paid): ?>
<section id="soporte" class="summary-card support-card" aria-label="Soporte del pedido">
    <h2>¿Necesitas ayuda con este pedido?</h2>
    <p class="muted">Cuéntanos qué ha pasado y te responderemos por email. Prototipo académico: no escribas datos personales reales.</p>
    <?php if (!empty($detail['tickets'])): ?>
        <ul class="support-list">
            <?php foreach ($detail['tickets'] as $ticket): ?>
                <li><strong><?= $esc($ticket['subject']) ?></strong> <small class="muted">· <?= $esc(\App\Support\Format::date($ticket['created_at'])) ?> · solicitud n.º <?= (int) $ticket['id'] ?> · <?= $ticket['status'] === 'open' ? 'abierta' : $esc($ticket['status']) ?></small></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <form class="checkout-form" method="post" action="/pedido/<?= rawurlencode((string) $o['code']) ?>/soporte">
        <input type="hidden" name="_csrf" value="<?= $esc($csrfToken ?? '') ?>">
        <div class="form-field">
            <label for="support-subject">Motivo</label>
            <select id="support-subject" name="subject" required>
                <option value="">Elige un motivo…</option>
                <?php foreach (($supportSubjects ?? []) as $key => $text): ?>
                    <option value="<?= $esc($key) ?>"<?= (($supportOld['subject'] ?? '') === $key) ? ' selected' : '' ?>><?= $esc($text) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($supportErrors['subject'])): ?><small class="field-error"><?= $esc($supportErrors['subject']) ?></small><?php endif; ?>
        </div>
        <div class="form-field">
            <label for="support-message">Mensaje</label>
            <textarea id="support-message" name="message" rows="4" minlength="10" maxlength="1000" required><?= $esc($supportOld['message'] ?? '') ?></textarea>
            <?php if (isset($supportErrors['message'])): ?><small class="field-error"><?= $esc($supportErrors['message']) ?></small><?php endif; ?>
        </div>
        <button class="button secondary" type="submit">Enviar solicitud</button>
    </form>
</section>
<?php endif; ?>

<script>
(function () {
    var box = document.querySelector('.tracking');
    if (!box || !window.fetch) { return; }
    var url = box.getAttribute('data-status-url');
    var badge = document.getElementById('order-status-badge');
    var current = document.getElementById('tracking-current');
    var list = document.getElementById('tracking-list');
    var last = <?= json_encode((string) $o['status'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var timer = null;

    function render(data) {
        badge.className = 'badge badge-' + data.status;
        badge.textContent = data.label;
        current.textContent = data.label;
        list.textContent = '';
        data.timeline.forEach(function (step) {
            var li = document.createElement('li');
            var b = document.createElement('span');
            b.className = 'badge badge-' + step.status;
            b.textContent = step.label;
            var d = document.createElement('small');
            d.className = 'muted';
            d.textContent = ' ' + step.at;
            li.appendChild(b);
            li.appendChild(d);
            list.appendChild(li);
        });
    }

    function poll() {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) { return; }
                if (data.status !== last) { last = data.status; render(data); }
                if (data.status === 'cancelled') { clearInterval(timer); }
            })
            .catch(function () {});
    }

    timer = setInterval(poll, 10000);
})();
</script>
