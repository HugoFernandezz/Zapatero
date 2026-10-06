<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$isEdit = $product !== null;
$action = $isEdit ? '/admin/productos/' . (int) $product['id'] : '/admin/productos';
$val = static fn (string $field): string => htmlspecialchars((string) ($form[$field] ?? ''), ENT_QUOTES, 'UTF-8');
$err = static function (string $field) use ($errors, $esc): string {
    return isset($errors[$field]) ? '<small class="field-error">' . $esc($errors[$field]) . '</small>' : '';
};
$adminSection = 'productos';
include __DIR__ . '/_nav.php';
?>
<section class="page-heading admin-heading">
    <div>
        <p class="eyebrow"><a class="text-link" href="/admin/productos">← Todos los productos</a></p>
        <h1><?= $isEdit ? 'Editar producto' : 'Nuevo producto' ?></h1>
    </div>
</section>

<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>
<?php if ($errors !== []): ?><p class="notice error" role="alert">Revisa los campos marcados.</p><?php endif; ?>

<form class="checkout-form product-admin-form" method="post" action="<?= $esc($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">

    <div class="form-field">
        <label for="name">Nombre</label>
        <input id="name" name="name" maxlength="120" required value="<?= $val('name') ?>">
        <?= $err('name') ?>
    </div>
    <div class="form-field">
        <label for="slug">Slug (URL) <small class="muted">— déjalo vacío para generarlo desde el nombre</small></label>
        <input id="slug" name="slug" maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $val('slug') ?>">
        <?= $err('slug') ?>
    </div>
    <div class="form-row">
        <div class="form-field">
            <label for="collection_id">Colección</label>
            <select id="collection_id" name="collection_id" required>
                <option value="">Selecciona…</option>
                <?php foreach ($collections as $c): ?>
                    <option value="<?= (int) $c['id'] ?>"<?= (string) $c['id'] === (string) ($form['collection_id'] ?? '') ? ' selected' : '' ?>><?= $esc($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('collection_id') ?>
        </div>
        <div class="form-field">
            <label for="style_id">Estilo</label>
            <select id="style_id" name="style_id" required>
                <option value="">Selecciona…</option>
                <?php foreach ($styles as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"<?= (string) $s['id'] === (string) ($form['style_id'] ?? '') ? ' selected' : '' ?>><?= $esc($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?= $err('style_id') ?>
        </div>
    </div>
    <div class="form-field">
        <label for="description">Descripción</label>
        <textarea id="description" name="description" rows="3" maxlength="1000" required><?= $val('description') ?></textarea>
        <?= $err('description') ?>
    </div>
    <div class="form-field">
        <label for="material">Material</label>
        <input id="material" name="material" maxlength="300" required value="<?= $val('material') ?>">
        <?= $err('material') ?>
    </div>
    <div class="form-field">
        <label for="price">Precio en euros (IVA incluido)</label>
        <input id="price" name="price" inputmode="decimal" placeholder="64,90" required value="<?= $val('price') ?>">
        <?= $err('price') ?>
    </div>

    <div class="form-field">
        <label for="image">Imagen (JPG, PNG o WebP, máximo 2 MB)<?= $isEdit ? ' — opcional, sustituye a la actual' : '' ?></label>
        <?php if (!empty($form['image'])): ?><img class="admin-thumb large" src="<?= $esc($form['image']) ?>" alt="Imagen actual"><?php endif; ?>
        <input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp"<?= $isEdit || !empty($form['image']) ? '' : ' required' ?>>
        <?= $err('image') ?>
    </div>

    <fieldset class="stock-fieldset">
        <legend>Stock por talla (EU)</legend>
        <p class="muted">Pon 0 para marcar una talla como sin stock.</p>
        <div class="stock-grid">
            <?php foreach ($sizes as $size): ?>
                <label for="stock-<?= (int) $size ?>">
                    <span><?= (int) $size ?></span>
                    <input id="stock-<?= (int) $size ?>" type="number" name="stock[<?= (int) $size ?>]" min="0" max="9999" step="1" value="<?= $esc($form['stock'][$size] ?? '0') ?>">
                </label>
            <?php endforeach; ?>
        </div>
        <?= $err('stock') ?>
    </fieldset>

    <button class="button primary" type="submit"><?= $isEdit ? 'Guardar cambios' : 'Crear producto' ?></button>
</form>
