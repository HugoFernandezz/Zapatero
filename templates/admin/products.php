<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => \App\Support\Format::money($cents);
$adminSection = 'productos';
include __DIR__ . '/_nav.php';
?>
<section class="page-heading admin-heading">
    <div>
        <p class="eyebrow">Back-office</p>
        <h1>Productos</h1>
    </div>
    <a class="button primary" href="/admin/productos/nuevo">Nuevo producto</a>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<?php if ($rows === []): ?>
    <section class="empty-state"><h2>No hay productos</h2><p>Crea el primero con el botón «Nuevo producto».</p></section>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Imagen</th><th>Producto</th><th>Colección · Estilo</th><th>Precio</th><th>Stock</th><th>Acciones</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?php if (!empty($row['image'])): ?><img class="admin-thumb" src="<?= $esc($row['image']) ?>" alt=""><?php endif; ?></td>
                        <td><strong><?= $esc($row['name']) ?></strong><br><small class="muted"><?= $esc($row['slug']) ?></small></td>
                        <td><?= $esc($row['collection_name']) ?> · <?= $esc($row['style_name']) ?></td>
                        <td><?= $money((int) $row['price_cents']) ?></td>
                        <td><?= (int) $row['total_stock'] ?> uds.</td>
                        <td class="row-actions">
                            <a class="button secondary" href="/admin/productos/<?= (int) $row['id'] ?>">Editar</a>
                            <form method="post" action="/admin/productos/<?= (int) $row['id'] ?>/eliminar" onsubmit="return confirm('¿Eliminar «<?= $esc(addslashes((string) $row['name'])) ?>»? Esta acción no se puede deshacer.');">
                                <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
                                <button class="button secondary danger" type="submit">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
