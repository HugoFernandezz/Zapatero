<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>

<section class="hero">
    <div class="hero-copy">
        <p class="eyebrow">Zapatillas casual con diseño gráfico</p>
        <h1>Zapatero</h1>
        <p>Un catálogo estable de zapatillas urbanas con estampados originales, stock por talla y colecciones pensadas para el día a día.</p>
        <div class="hero-actions">
            <a class="button primary" href="/catalogo">Ver catálogo</a>
        </div>
    </div>
    <div class="hero-media">
        <img src="/assets/img/products/avenida-neon.png" alt="Zapatilla Avenida Neón con diseño urbano">
    </div>
</section>

<section class="section-block">
    <div class="section-heading">
        <p class="eyebrow">Colecciones</p>
        <h2>Cuatro líneas de diseño</h2>
    </div>

    <div class="collection-grid">
        <?php foreach ($collections as $collection): ?>
            <a class="collection-card" href="/catalogo?coleccion=<?= $esc($collection['slug']) ?>">
                <span><?= $esc($collection['name']) ?></span>
                <p><?= $esc($collection['description']) ?></p>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<section class="section-block">
    <div class="section-heading with-action">
        <div>
            <p class="eyebrow">Selección inicial</p>
            <h2>Modelos destacados</h2>
        </div>
        <a class="text-link" href="/catalogo">Explorar todos</a>
    </div>

    <div class="product-grid">
        <?php foreach ($featuredProducts as $product): ?>
            <article class="product-card">
                <a href="/producto/<?= $esc($product['slug']) ?>">
                    <img src="<?= $esc($product['image']) ?>" alt="<?= $esc($product['name']) ?>">
                </a>
                <div class="product-card-body">
                    <p class="meta"><?= $esc($product['collection_name']) ?> · <?= $esc($product['style_name']) ?></p>
                    <h3><a href="/producto/<?= $esc($product['slug']) ?>"><?= $esc($product['name']) ?></a></h3>
                    <p class="price-small"><?= $money((int) $product['price_cents']) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
