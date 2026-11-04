<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>
<section class="page-heading">
    <p class="eyebrow">Tu selección</p>
    <h1>Carrito</h1>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<?php if ($summary['items'] === []): ?>
    <section class="empty-state">
        <h2>Tu carrito está vacío</h2>
        <p>Explora el catálogo y añade tu próximo par favorito.</p>
        <a class="button primary" href="/catalogo">Ver catálogo</a>
    </section>
<?php else: ?>
    <section class="cart-layout">
        <div class="cart-lines">
            <?php foreach ($summary['items'] as $item): ?>
                <article class="cart-line">
                    <img src="<?= $esc($item['image']) ?>" alt="<?= $esc($item['name']) ?>">
                    <div class="cart-line-info">
                        <h2><?= $esc($item['name']) ?></h2>
                        <p>Talla EU <?= (int) $item['size_eu'] ?> · <?= $money((int) $item['price_cents']) ?> / unidad</p>
                        <form method="post" action="/carrito/actualizar" class="quantity-form">
                            <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
                            <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                            <label for="quantity-<?= (int) $item['variant_id'] ?>">Cantidad</label>
                            <input id="quantity-<?= (int) $item['variant_id'] ?>" type="number" name="quantity" min="0" max="<?= (int) $item['stock'] ?>" value="<?= (int) $item['quantity'] ?>" required>
                            <button class="button secondary" type="submit">Actualizar</button>
                            <small>Stock disponible: <?= (int) $item['stock'] ?> · cantidad 0 elimina el artículo</small>
                        </form>
                    </div>
                    <strong><?= $money((int) $item['line_total_cents']) ?></strong>
                </article>
            <?php endforeach; ?>
        </div>

        <aside class="summary-card" aria-label="Resumen del pedido">
            <h2>Resumen</h2>
            <form method="post" action="/carrito/descuento" class="discount-form">
                <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
                <label for="discount_code">Código de descuento</label>
                <input id="discount_code" name="discount_code" value="<?= $esc($enteredCode ?? '') ?>" maxlength="32" placeholder="BIENVENIDA10 o ZAP5">
                <button class="button secondary" type="submit">Aplicar</button>
            </form>
            <dl class="totals">
                <div><dt>Subtotal (IVA incluido)</dt><dd><?= $money((int) $summary['subtotal_cents']) ?></dd></div>
                <?php if ($summary['discount_cents'] > 0): ?>
                    <div><dt>Descuento <?= $esc($summary['discount_code']) ?></dt><dd>−<?= $money((int) $summary['discount_cents']) ?></dd></div>
                <?php endif; ?>
                <div><dt>Envío</dt><dd><?= $summary['shipping_cents'] === 0 ? 'Gratis' : $money((int) $summary['shipping_cents']) ?></dd></div>
                <div><dt>IVA incluido (21 %)</dt><dd><?= $money((int) $summary['tax_cents']) ?></dd></div>
                <div class="grand-total"><dt>Total</dt><dd><?= $money((int) $summary['total_cents']) ?></dd></div>
            </dl>
            <?php if (empty($_SESSION['user_id'])): ?>
                <p class="muted"><a class="text-link" href="/login?next=/carrito">Inicia sesión</a> y obtén un <?= (int) \App\Services\CartService::MEMBER_PERCENT ?> % de descuento en esta compra.</p>
            <?php endif; ?>
            <?php if ($summary['subtotal_cents'] < 6000): ?><p class="muted">Envío gratis desde 60,00 € de subtotal.</p><?php endif; ?>
            <a class="button primary full-width" href="/checkout">Continuar al checkout</a>
            <a class="text-link" href="/catalogo">Seguir comprando</a>
        </aside>
    </section>
<?php endif; ?>
