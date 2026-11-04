<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => \App\Support\Format::money($cents);
$link = static function (array $overrides) use ($status, $query): string {
    $params = array_filter(array_merge(['status' => $status, 'q' => $query], $overrides), static fn ($v) => $v !== '' && $v !== null);
    return '/admin/pedidos' . ($params === [] ? '' : '?' . http_build_query($params));
};
$allCount = array_sum($statusCounts);
?>
<?php $adminSection = 'pedidos'; include __DIR__ . '/_nav.php'; ?>
<section class="page-heading admin-heading">
    <div>
        <p class="eyebrow">Back-office</p>
        <h1>Pedidos</h1>
    </div>
</section>

<?php if (!empty($success)): ?><p class="notice success" role="status"><?= $esc($success) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="notice error" role="alert"><?= $esc($error) ?></p><?php endif; ?>

<nav class="status-chips" aria-label="Filtrar por estado">
    <a class="chip<?= $status === '' ? ' active' : '' ?>" href="<?= $esc($link(['status' => '', 'page' => ''])) ?>">Todos <strong><?= (int) $allCount ?></strong></a>
    <?php foreach (\App\Support\OrderStatus::all() as $s): ?>
        <a class="chip<?= $status === $s ? ' active' : '' ?>" href="<?= $esc($link(['status' => $s, 'page' => ''])) ?>"><?= $esc(\App\Support\OrderStatus::label($s)) ?> <strong><?= (int) ($statusCounts[$s] ?? 0) ?></strong></a>
    <?php endforeach; ?>
</nav>

<form class="admin-search" method="get" action="/admin/pedidos">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $esc($status) ?>"><?php endif; ?>
    <label for="q">Buscar</label>
    <input id="q" type="search" name="q" value="<?= $esc($query) ?>" maxlength="80" placeholder="Código, nombre o email">
    <button class="button secondary" type="submit">Buscar</button>
    <?php if ($query !== ''): ?><a class="text-link" href="<?= $esc($link(['q' => '', 'page' => ''])) ?>">Limpiar</a><?php endif; ?>
</form>

<?php if ($rows === []): ?>
    <section class="empty-state"><h2>No hay pedidos</h2><p>Ningún pedido coincide con el filtro actual.</p></section>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Pedido</th><th>Fecha</th><th>Cliente</th><th>Uds.</th><th>Total</th><th>Estado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a class="text-link" href="/admin/pedidos/<?= rawurlencode((string) $row['code']) ?>"><?= $esc($row['code']) ?></a></td>
                        <td><?= $esc(\App\Support\Format::date($row['created_at'])) ?></td>
                        <td><?= $esc($row['customer_name']) ?><br><small class="muted"><?= $esc($row['customer_email']) ?></small></td>
                        <td><?= (int) $row['units'] ?></td>
                        <td><?= $money((int) $row['total_cents']) ?></td>
                        <td><span class="badge badge-<?= $esc($row['status']) ?>"><?= $esc(\App\Support\OrderStatus::label((string) $row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="pagination" aria-label="Paginación">
        <?php if ($page > 1): ?><a class="button secondary" href="<?= $esc($link(['page' => (string) ($page - 1)])) ?>">← Anterior</a><?php endif; ?>
        <span>Página <?= (int) $page ?> de <?= (int) $pages ?> · <?= (int) $total ?> pedidos</span>
        <?php if ($page < $pages): ?><a class="button secondary" href="<?= $esc($link(['page' => (string) ($page + 1)])) ?>">Siguiente →</a><?php endif; ?>
    </nav>
<?php endif; ?>
