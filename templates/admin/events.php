<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$active = array_filter($filters, static fn ($v) => $v !== '');
$link = static function (array $overrides) use ($filters): string {
    $params = array_filter(array_merge($filters, $overrides), static fn ($v) => $v !== '' && $v !== null);
    return '/admin/eventos' . ($params === [] ? '' : '?' . http_build_query($params));
};
$exportQuery = $active === [] ? '' : '?' . http_build_query($active);
$types = array_values(array_unique(array_merge($required, array_keys($counts))));
sort($types);
?>
<?php $adminSection = 'eventos'; include __DIR__ . '/_nav.php'; ?>
<section class="page-heading admin-heading">
    <div>
        <p class="eyebrow">Back-office</p>
        <h1>Registro de eventos</h1>
        <p class="muted">Eventos de negocio guardados en la tabla <code>events</code> (SQLite). Se pueden exportar para que otro sistema los consuma.</p>
    </div>
    <div class="admin-actions">
        <a class="button secondary" href="/admin/eventos/exportar/json<?= $esc($exportQuery) ?>">Exportar JSON</a>
        <a class="button secondary" href="/admin/eventos/exportar/csv<?= $esc($exportQuery) ?>">Exportar CSV</a>
    </div>
</section>

<nav class="status-chips" aria-label="Filtrar por tipo de evento">
    <a class="chip<?= $filters['type'] === '' ? ' active' : '' ?>" href="<?= $esc($link(['type' => '', 'page' => ''])) ?>">Todos <strong><?= (int) array_sum($counts) ?></strong></a>
    <?php foreach ($types as $type): ?>
        <?php $n = (int) ($counts[$type] ?? 0); ?>
        <a class="chip<?= $filters['type'] === $type ? ' active' : '' ?>" href="<?= $esc($link(['type' => $type, 'page' => ''])) ?>"><?= $esc($type) ?> <strong><?= $n ?></strong></a>
    <?php endforeach; ?>
</nav>

<form class="admin-search" method="get" action="/admin/eventos">
    <?php if ($filters['type'] !== ''): ?><input type="hidden" name="type" value="<?= $esc($filters['type']) ?>"><?php endif; ?>
    <label for="q">Buscar</label>
    <input id="q" type="search" name="q" value="<?= $esc($filters['q']) ?>" maxlength="80" placeholder="Código de pedido o sesión">
    <label for="from">Desde</label>
    <input id="from" type="date" name="from" value="<?= $esc($filters['from']) ?>">
    <label for="to">Hasta</label>
    <input id="to" type="date" name="to" value="<?= $esc($filters['to']) ?>">
    <button class="button secondary" type="submit">Filtrar</button>
    <?php if ($active !== []): ?><a class="text-link" href="/admin/eventos">Limpiar</a><?php endif; ?>
</form>

<p class="muted"><?= (int) $total ?> evento(s) con el filtro actual.</p>

<?php if ($rows === []): ?>
    <section class="empty-state"><h2>No hay eventos</h2><p>Ningún evento coincide con el filtro actual.</p></section>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>#</th><th>Fecha (UTC)</th><th>Tipo</th><th>Sesión</th><th>Usuario</th><th>Datos (payload)</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><?= $esc(str_replace(['T', 'Z'], [' ', ''], $row['occurred_at'])) ?></td>
                        <td><strong><?= $esc($row['type']) ?></strong></td>
                        <td><small><?= $esc(substr((string) ($row['session_id'] ?? ''), 0, 8)) ?></small></td>
                        <td><?= $row['user_id'] !== null ? (int) $row['user_id'] : '—' ?></td>
                        <td><code><?= $esc(mb_strimwidth((string) $row['payload'], 0, 160, '…')) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
        <nav class="pagination" aria-label="Paginación">
            <?php if ($page > 1): ?><a class="button secondary" href="<?= $esc($link(['page' => (string) ($page - 1)])) ?>">← Anterior</a><?php endif; ?>
            <span class="muted">Página <?= (int) $page ?> de <?= (int) $pages ?></span>
            <?php if ($page < $pages): ?><a class="button secondary" href="<?= $esc($link(['page' => (string) ($page + 1)])) ?>">Siguiente →</a><?php endif; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
