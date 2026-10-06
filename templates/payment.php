<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$value = static fn (string $field): string => $esc($formData[$field] ?? '');
?>
<section class="page-heading">
    <p class="eyebrow">Último paso</p>
    <h1>Pago</h1>
    <p>Pasarela <strong>simulada</strong>: no se realiza ningún cobro real. Usa datos ficticios.</p>
</section>

<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<section class="checkout-layout">
    <form class="checkout-form" method="post" action="/pago" novalidate>
        <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">

        <div class="test-cards" role="note">
            <strong>Tarjetas de prueba</strong>
            <ul>
                <li>Cualquier número de tarjeta → pago aprobado</li>
                <li><code>0000 0000 0000 0000</code> → pago denegado</li>
            </ul>
            <small>Caducidad futura (p. ej. 12/30) y cualquier CVC de 3 cifras.</small>
        </div>

        <div class="form-field">
            <label for="card_holder">Titular de la tarjeta</label>
            <input id="card_holder" name="card_holder" autocomplete="cc-name" maxlength="80" required value="<?= $value('card_holder') ?>" aria-describedby="error-card_holder">
            <?php if (isset($errors['card_holder'])): ?><small id="error-card_holder" class="field-error"><?= $esc($errors['card_holder']) ?></small><?php endif; ?>
        </div>
        <div class="form-field">
            <label for="card_number">Número de tarjeta</label>
            <input id="card_number" name="card_number" autocomplete="cc-number" inputmode="numeric" maxlength="23" placeholder="1234 5678 9012 3456" required aria-describedby="error-card_number">
            <?php if (isset($errors['card_number'])): ?><small id="error-card_number" class="field-error"><?= $esc($errors['card_number']) ?></small><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label for="card_expiry">Caducidad (MM/AA)</label>
                <input id="card_expiry" name="card_expiry" autocomplete="cc-exp" inputmode="numeric" maxlength="7" placeholder="12/30" required value="<?= $value('card_expiry') ?>" aria-describedby="error-card_expiry">
                <?php if (isset($errors['card_expiry'])): ?><small id="error-card_expiry" class="field-error"><?= $esc($errors['card_expiry']) ?></small><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="card_cvc">CVC</label>
                <input id="card_cvc" name="card_cvc" autocomplete="cc-csc" inputmode="numeric" maxlength="3" placeholder="123" required aria-describedby="error-card_cvc">
                <?php if (isset($errors['card_cvc'])): ?><small id="error-card_cvc" class="field-error"><?= $esc($errors['card_cvc']) ?></small><?php endif; ?>
            </div>
        </div>
        <button class="button primary" type="submit">Pagar <?= $money((int) $summary['total_cents']) ?></button>
        <p class="muted">No guardamos el número de tarjeta ni el CVC; solo los 4 últimos dígitos del pago simulado.</p>
        <a class="text-link" href="/checkout">← Modificar datos de envío</a>
    </form>

    <aside class="summary-card" aria-label="Resumen del pedido">
        <h2>Resumen</h2>
        <?php foreach ($summary['items'] as $item): ?>
            <div class="checkout-item"><span><?= $esc($item['name']) ?> · EU <?= (int) $item['size_eu'] ?> × <?= (int) $item['quantity'] ?></span><strong><?= $money((int) $item['line_total_cents']) ?></strong></div>
        <?php endforeach; ?>
        <dl class="totals">
            <div><dt>Subtotal</dt><dd><?= $money((int) $summary['subtotal_cents']) ?></dd></div>
            <?php if ($summary['discount_cents'] > 0): ?><div><dt>Descuento <?= $esc($summary['discount_code']) ?></dt><dd>−<?= $money((int) $summary['discount_cents']) ?></dd></div><?php endif; ?>
            <div><dt>Envío</dt><dd><?= $summary['shipping_cents'] === 0 ? 'Gratis' : $money((int) $summary['shipping_cents']) ?></dd></div>
            <div><dt>IVA incluido (21 %)</dt><dd><?= $money((int) $summary['tax_cents']) ?></dd></div>
            <div class="grand-total"><dt>Total</dt><dd><?= $money((int) $summary['total_cents']) ?></dd></div>
        </dl>
        <h3 class="summary-sub">Envío a</h3>
        <p class="muted">
            <?= $esc($shipping['full_name'] ?? '') ?><br>
            <?= $esc($shipping['address'] ?? '') ?><br>
            <?= $esc($shipping['postal_code'] ?? '') ?> <?= $esc($shipping['city'] ?? '') ?> (<?= $esc($shipping['province'] ?? '') ?>)<br>
            Factura a: <?= $esc($shipping['email'] ?? '') ?>
        </p>
    </aside>
</section>
