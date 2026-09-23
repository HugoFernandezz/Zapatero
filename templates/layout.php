<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Zapatero', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/css/styles.css">
</head>
<body>
    <div class="prototype-banner" role="note">
        <strong>Prototipo académico:</strong> esta tienda es ficticia. No se realizan pagos reales, envíos reales ni tratamiento de datos personales reales.
    </div>

    <header class="site-header">
        <a class="brand" href="/" aria-label="Inicio de Zapatero">
            <span class="brand-mark">Z</span>
            <span>Zapatero</span>
        </a>
        <nav class="main-nav" aria-label="Navegación principal">
            <a href="/">Home</a>
            <a href="/catalogo">Catálogo</a>
        </nav>
    </header>

    <main>
        <?= $content ?>
    </main>

    <footer class="site-footer">
        <p>Zapatero · prototipo académico de eCommerce de zapatillas.</p>
    </footer>
</body>
</html>
