<?php

// Borra y recrea la base de datos: php database/init.php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($root)->safeLoad();

$path = $root . '/' . ($_ENV['DB_PATH'] ?? 'storage/zapatero.sqlite');

foreach ([$path, "$path-wal", "$path-shm"] as $file) {
    if (file_exists($file)) {
        unlink($file);
    }
}

App\Database::connect($path);

echo "Base de datos creada en $path" . PHP_EOL;
