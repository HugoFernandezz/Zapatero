<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>

<nav class="breadcrumbs" aria-label="Migas de pan">
    <a href="/catalogo">Catálogo</a>
    <span>/</span>
    <a href="/catalogo?coleccion=<?= $esc($product['collection_slug']) ?>"><?= $esc($product['collection_name']) ?></a>
    <span>/</span>
    <span><?= $esc($product['name']) ?></span>
</nav>

<section class="product-detail">
    <div class="product-gallery">
        <img src="<?= $esc($product['image']) ?>" alt="<?= $esc($product['name']) ?>">
    </div>

    <div class="product-info">
        <p class="eyebrow"><?= $esc($product['collection_name']) ?> · <?= $esc($product['style_name']) ?></p>
        <h1><?= $esc($product['name']) ?></h1>
        <p class="price"><?= $money((int) $product['price_cents']) ?></p>
        <p><?= $esc($product['description']) ?></p>

        <dl class="product-specs">
            <div>
                <dt>Material</dt>
                <dd><?= $esc($product['material']) ?></dd>
            </div>
            <div>
                <dt>Stock total</dt>
                <dd><?= (int) $product['total_stock'] ?> unidades disponibles</dd>
            </div>
        </dl>

        <form class="add-to-cart-form" method="post" action="/carrito/agregar">
            <input type="hidden" name="producto_slug" value="<?= $esc($product['slug']) ?>">
            <input type="hidden" name="return_to" value="/producto/<?= $esc($product['slug']) ?>">

            <fieldset>
                <legend>Selecciona talla EU</legend>
                <div class="size-grid">
                    <?php foreach ($product['variants'] as $variant): ?>
                        <?php $hasStock = (int) $variant['stock'] > 0; ?>
                        <label class="<?= $hasStock ? '' : 'is-empty' ?>">
                            <input
                                type="radio"
                                name="variant_id"
                                value="<?= (int) $variant['id'] ?>"
                                required
                                <?= $hasStock ? '' : 'disabled' ?>
                            >
                            <span><?= (int) $variant['size_eu'] ?></span>
                            <small><?= $hasStock ? (int) $variant['stock'] . ' uds.' : 'Sin stock' ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <button class="button primary" type="submit">Añadir al carrito</button>
            <p class="form-hint">Contrato para M3: POST /carrito/agregar con variant_id y producto_slug.</p>
        </form>
    </div>
</section>
