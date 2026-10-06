<?php

// Reaplica el catálogo y el cliente de prueba sobre la base existente: php database/seed.php
// (es idempotente; una base nueva ya se siembra sola al crearse).

declare(strict_types=1);

use App\CatalogSeeder;
use App\Database;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();

$path = $root . '/' . ($_ENV['DB_PATH'] ?? 'storage/zapatero.sqlite');
$pdo = Database::connect($path);

CatalogSeeder::run($pdo);

$products = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
echo "Seed aplicado en $path con $products productos" . PHP_EOL;
