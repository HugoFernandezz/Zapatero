<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
?>

<section class="page-heading">
    <p class="eyebrow">Catálogo</p>
    <h1>Zapatillas por colección, estilo y talla</h1>
</section>

<section class="catalog-layout">
    <aside class="filters-panel" aria-label="Filtros del catálogo">
        <form method="get" action="/catalogo">
            <label for="coleccion">Colección</label>
            <select id="coleccion" name="coleccion">
                <option value="">Todas</option>
                <?php foreach ($collections as $collection): ?>
                    <option value="<?= $esc($collection['slug']) ?>" <?= $filters['coleccion'] === $collection['slug'] ? 'selected' : '' ?>>
                        <?= $esc($collection['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="estilo">Estilo</label>
            <select id="estilo" name="estilo">
                <option value="">Todos</option>
                <?php foreach ($styles as $style): ?>
                    <option value="<?= $esc($style['slug']) ?>" <?= $filters['estilo'] === $style['slug'] ? 'selected' : '' ?>>
                        <?= $esc($style['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="talla">Talla EU</label>
            <select id="talla" name="talla">
                <option value="">Todas</option>
                <?php foreach ($sizes as $size): ?>
                    <option value="<?= $size ?>" <?= $filters['talla'] === $size ? 'selected' : '' ?>>
                        EU <?= $size ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div class="filter-actions">
                <button class="button primary" type="submit">Filtrar</button>
                <a class="button secondary" href="/catalogo">Limpiar</a>
            </div>
        </form>
    </aside>

    <div class="catalog-results">
        <p class="results-count">
            <strong><?= count($products) ?></strong>
            <?= count($products) === 1 ? 'modelo encontrado' : 'modelos encontrados' ?>
        </p>

        <?php if ($products === []): ?>
            <div class="empty-state">
                <h2>No hay modelos con esos filtros</h2>
                <p>Prueba otra colección, estilo o talla disponible.</p>
            </div>
        <?php else: ?>
            <div class="product-grid">
                <?php foreach ($products as $product): ?>
                    <article class="product-card">
                        <a href="/producto/<?= $esc($product['slug']) ?>">
                            <img src="<?= $esc($product['image']) ?>" alt="<?= $esc($product['name']) ?>">
                        </a>
                        <div class="product-card-body">
                            <p class="meta"><?= $esc($product['collection_name']) ?> · <?= $esc($product['style_name']) ?></p>
                            <h2><a href="/producto/<?= $esc($product['slug']) ?>"><?= $esc($product['name']) ?></a></h2>
                            <p class="description"><?= $esc($product['description']) ?></p>
                            <div class="product-card-footer">
                                <strong><?= $money((int) $product['price_cents']) ?></strong>
                                <?php if ((int) $product['total_stock'] > 0): ?>
                                    <span>Disponible EU <?= (int) $product['min_available_size'] ?>-<?= (int) $product['max_available_size'] ?></span>
                                <?php else: ?>
                                    <span class="stock-empty">Sin stock</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
