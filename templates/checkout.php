<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
$value = static fn (string $field): string => $esc($formData[$field] ?? '');
?>
<section class="page-heading">
    <p class="eyebrow">Último paso</p>
    <h1>Datos de envío</h1>
    <p>Prototipo académico: usa exclusivamente datos ficticios. Aquí no se solicitan datos de pago.</p>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<section class="checkout-layout">
    <form class="checkout-form" method="post" action="/checkout">
        <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
        <div class="form-field">
            <label for="full_name">Nombre y apellidos</label>
            <input id="full_name" name="full_name" autocomplete="name" maxlength="160" pattern="[\p{L}\p{M} '’-]+" title="Solo letras, espacios, guiones y apóstrofos." required value="<?= $value('full_name') ?>" aria-describedby="error-full_name">
            <?php if (isset($errors['full_name'])): ?><small id="error-full_name" class="field-error"><?= $esc($errors['full_name']) ?></small><?php endif; ?>
        </div>
        <div class="form-field">
            <label for="email">Email de contacto</label>
            <input id="email" type="email" name="email" autocomplete="email" maxlength="254" required value="<?= $value('email') ?>" aria-describedby="error-email">
            <?php if (isset($errors['email'])): ?><small id="error-email" class="field-error"><?= $esc($errors['email']) ?></small><?php endif; ?>
        </div>
        <div class="form-field">
            <label for="address">Dirección</label>
            <input id="address" name="address" autocomplete="street-address" maxlength="160" required value="<?= $value('address') ?>" aria-describedby="error-address">
            <?php if (isset($errors['address'])): ?><small id="error-address" class="field-error"><?= $esc($errors['address']) ?></small><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label for="postal_code">Código postal</label>
                <input id="postal_code" name="postal_code" autocomplete="postal-code" inputmode="numeric" pattern="[0-9]{5}" minlength="5" maxlength="5" required value="<?= $value('postal_code') ?>" aria-describedby="error-postal_code">
                <?php if (isset($errors['postal_code'])): ?><small id="error-postal_code" class="field-error"><?= $esc($errors['postal_code']) ?></small><?php endif; ?>
            </div>
            <div class="form-field">
                <label for="city">Localidad</label>
                <input id="city" name="city" autocomplete="address-level2" maxlength="160" pattern="[\p{L}\p{M} '’-]+" title="Solo letras, espacios, guiones y apóstrofos." required value="<?= $value('city') ?>" aria-describedby="error-city">
                <?php if (isset($errors['city'])): ?><small id="error-city" class="field-error"><?= $esc($errors['city']) ?></small><?php endif; ?>
            </div>
        </div>
        <div class="form-field">
            <label for="province">Provincia</label>
            <input id="province" name="province" autocomplete="address-level1" maxlength="160" pattern="[\p{L}\p{M} '’-]+" title="Solo letras, espacios, guiones y apóstrofos." required value="<?= $value('province') ?>" aria-describedby="error-province">
            <?php if (isset($errors['province'])): ?><small id="error-province" class="field-error"><?= $esc($errors['province']) ?></small><?php endif; ?>
        </div>
        <button class="button primary" type="submit">Validar datos de envío</button>
        <p class="muted">El pedido y el pago simulado se conectarán con el módulo M4.</p>
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
    </aside>
</section>
