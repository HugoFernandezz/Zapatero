<?php
$esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn (int $cents): string => \App\Support\Format::money($cents);
?>
<section class="page-heading">
    <p class="eyebrow">Hola, <?= $esc($_SESSION['user_name'] ?? '') ?></p>
    <h1>Mis pedidos</h1>
    <p class="muted">El estado se actualiza automáticamente cuando lo cambia la tienda.</p>
</section>

<?php if ($rows === []): ?>
    <section class="empty-state">
        <h2>Aún no tienes pedidos</h2>
        <p>Cuando compres con tu cuenta, aparecerán aquí con su estado.</p>
        <a class="button primary" href="/catalogo">Ver catálogo</a>
    </section>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Pedido</th><th>Fecha</th><th>Uds.</th><th>Total</th><th>Estado</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a class="text-link" href="/pedido/<?= rawurlencode((string) $row['code']) ?>"><?= $esc($row['code']) ?></a></td>
                        <td><?= $esc(\App\Support\Format::date($row['created_at'])) ?></td>
                        <td><?= (int) $row['units'] ?></td>
                        <td><?= $money((int) $row['total_cents']) ?></td>
                        <td><span class="badge badge-<?= $esc($row['status']) ?>" data-order-status="<?= $esc($row['code']) ?>"><?= $esc(\App\Support\OrderStatus::label((string) $row['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <script>
    (function () {
        if (!window.fetch) { return; }
        function poll() {
            fetch('/mis-pedidos/estados', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (data) {
                    if (!data || !data.orders) { return; }
                    data.orders.forEach(function (o) {
                        document.querySelectorAll('[data-order-status]').forEach(function (el) {
                            if (el.getAttribute('data-order-status') === o.code) {
                                el.className = 'badge badge-' + o.status;
                                el.textContent = o.label;
                            }
                        });
                    });
                })
                .catch(function () {});
        }
        setInterval(poll, 15000);
    })();
    </script>
<?php endif; ?>
