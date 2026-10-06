<?php
// Navegación del back-office. Se incluye desde cada pantalla de /admin con $adminSection = 'pedidos' | 'productos' | 'eventos'.
$adminSection = $adminSection ?? '';
$navCsrf = \App\Support\Csrf::token();
?>
<nav class="admin-nav" aria-label="Back-office">
    <a class="chip<?= $adminSection === 'pedidos' ? ' active' : '' ?>" href="/admin/pedidos">Pedidos</a>
    <a class="chip<?= $adminSection === 'productos' ? ' active' : '' ?>" href="/admin/productos">Productos</a>
    <a class="chip<?= $adminSection === 'eventos' ? ' active' : '' ?>" href="/admin/eventos">Eventos</a>
    <form method="post" action="/logout" class="admin-nav-logout">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($navCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <span class="muted"><?= htmlspecialchars((string) ($_SESSION['admin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
        <button class="button secondary" type="submit">Salir</button>
    </form>
</nav>
