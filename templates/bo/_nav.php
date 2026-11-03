<?php
// Navegación del back-office de integración (/bo). Se incluye con $boSection = 'inicio'|'pedidos'|'tickets'|'agente'|'integracion'.
$boSection = $boSection ?? '';
$navCsrf = \App\Support\Csrf::token();
$esc = $esc ?? static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<nav class="admin-nav" aria-label="Back-office de integración">
    <a class="chip<?= $boSection === 'inicio' ? ' active' : '' ?>" href="/bo">Resumen</a>
    <a class="chip<?= $boSection === 'pedidos' ? ' active' : '' ?>" href="/bo/pedidos">Pedidos</a>
    <a class="chip<?= $boSection === 'tickets' ? ' active' : '' ?>" href="/bo/tickets">Tickets</a>
    <a class="chip<?= $boSection === 'agente' ? ' active' : '' ?>" href="/bo/agente">Agente IA</a>
    <a class="chip<?= $boSection === 'integracion' ? ' active' : '' ?>" href="/bo/integracion">Integración</a>
    <a class="chip<?= $boSection === 'metricas' ? ' active' : '' ?>" href="/bo/metricas">Métricas</a>
    <a class="chip" href="/admin/pedidos">← Tienda (admin)</a>
    <form method="post" action="/logout" class="admin-nav-logout">
        <input type="hidden" name="_csrf" value="<?= $esc($navCsrf) ?>">
        <span class="muted"><?= $esc($_SESSION['admin_email'] ?? '') ?></span>
        <button class="button secondary" type="submit">Salir</button>
    </form>
</nav>
