<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Zapatero', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/styles.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../public/assets/css/styles.css'), 0, 12) ?>">
</head>
<body>
    <div class="prototype-banner" role="note">
        <strong>Prototipo académico:</strong> no hay ventas, pagos ni envíos reales. Utiliza únicamente datos de prueba.
    </div>

    <header class="site-header">
        <a class="brand" href="/" aria-label="Inicio de Zapatero">
            <span class="brand-mark">Z</span>
            <span>Zapatero</span>
        </a>
        <nav class="main-nav" aria-label="Navegación principal">
            <a href="/">Home</a>
            <a href="/catalogo">Catálogo</a>
            <a href="/carrito">Carrito</a>
            <?php if (!empty($_SESSION['admin_id'])): ?><a href="/admin/pedidos">Admin</a><?php endif; ?>
            <?php if (!empty($_SESSION['user_id'])): ?>
                <?php if (empty($_SESSION['admin_id'])): ?><a href="/mis-pedidos">Mis pedidos</a><?php endif; ?>
                <form class="nav-logout" method="post" action="/logout">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="nav-user"><?= htmlspecialchars((string) ($_SESSION['user_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <button class="nav-link-button" type="submit">Salir</button>
                </form>
            <?php else: ?>
                <a class="nav-login" href="/login">Iniciar sesión</a>
            <?php endif; ?>
        </nav>
    </header>

    <main>
        <?php
        // Avisos que ninguna página ha consumido (por ejemplo, tras crear la cuenta).
        $flashOk = $_SESSION['_flash_success'] ?? null;
        $flashErr = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);
        ?>
        <?php if (!empty($flashOk)): ?><p class="notice success" role="status"><?= htmlspecialchars((string) $flashOk, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?php if (!empty($flashErr)): ?><p class="notice error" role="alert"><?= htmlspecialchars((string) $flashErr, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <div class="footer-grid">
            <div class="footer-intro">
                <a class="brand" href="/"><span class="brand-mark" aria-hidden="true">Z</span><span>Zapatero</span></a>
                <p>Una tienda de zapatillas creada para aprender a conectar pedidos, atención y gestión.</p>
                <p class="footer-prototype"><strong>Solo es una demostración.</strong><br>No se realizan ventas, cobros ni envíos reales.</p>
            </div>
            <nav aria-label="Sobre Zapatero">
                <h2>La tienda</h2>
                <a href="/carrito">Ver mi carrito</a>
                <a href="/politicas#como-funciona">Cómo funciona</a>
                <a href="/catalogo">Explorar el catálogo</a>
            </nav>
            <nav aria-label="Políticas del prototipo">
                <h2>Información y políticas</h2>
                <a href="/politicas#pagos-envios">Pagos y envíos simulados</a>
                <a href="/politicas#cambios-devoluciones">Cambios y devoluciones</a>
                <a href="/politicas#datos">Datos de prueba y cookies</a>
                <a href="/politicas#soporte">Ayuda y Telegram</a>
            </nav>
        </div>
        <p class="footer-bottom">Proyecto académico de Soluciones Informáticas para la Empresa · <a href="/politicas">Consultar todas las políticas</a></p>
    </footer>
</body>
</html>
