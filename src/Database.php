<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(string $path): PDO
    {
        $isNew = !file_exists($path);

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');

        // Si el fichero no existía se crea el esquema y el catálogo (así el servidor no necesita SSH).
        if ($isNew) {
            $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
            CatalogSeeder::run($pdo);
        }

        self::migrate($pdo);

        return $pdo;
    }

    /**
     * Cambios de esquema para bases de datos ya creadas (el hosting solo tiene FTP, sin comandos).
     * Son idempotentes: se ejecutan en cada petición y no hacen nada si ya existen.
     */
    private static function migrate(PDO $pdo): void
    {
        // Se consulta antes para no pedir bloqueo de escritura en cada petición cuando ya está todo creado.
        $has = static fn (string $type, string $name): bool => $pdo
            ->query("SELECT 1 FROM sqlite_master WHERE type = '" . $type . "' AND name = '" . $name . "'")
            ->fetchColumn() !== false;

        if (!$has('table', 'cart_items')) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS cart_items (
                    user_id    INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE,
                    variant_id INTEGER NOT NULL REFERENCES product_variants (id) ON DELETE CASCADE,
                    quantity   INTEGER NOT NULL CHECK (quantity > 0),
                    PRIMARY KEY (user_id, variant_id)
                )'
            );
        }
        // Bases creadas antes de existir los códigos de descuento.
        if ($pdo->query('SELECT 1 FROM discount_codes LIMIT 1')->fetchColumn() === false) {
            CatalogSeeder::seedDiscountCodes($pdo);
        }
        if (!$has('index', 'idx_orders_user')) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_user ON orders (user_id)');
        }
    }
}
