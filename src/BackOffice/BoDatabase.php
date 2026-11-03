<?php

declare(strict_types=1);

namespace App\BackOffice;

use PDO;

/** Conexión a la base de datos PROPIA del back-office (separada de la de la tienda). */
final class BoDatabase
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
        $pdo->exec('PRAGMA busy_timeout = 5000');

        // Sin SSH en el hosting: si el fichero no existe se crea el esquema en la primera petición.
        if ($isNew) {
            $pdo->exec((string) file_get_contents(__DIR__ . '/../../database/backoffice-schema.sql'));
        }

        // Migración idempotente: añade la tabla de tokens de Telegram también a bases ya creadas.
        $pdo->exec(self::MIGRATIONS);

        return $pdo;
    }

    /** Tokens de un solo uso para vincular el Telegram de un cliente con su pedido (se guarda solo el hash SHA-256). */
    private const MIGRATIONS = <<<'SQL'
CREATE TABLE IF NOT EXISTS bo_telegram_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    order_code TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    used_at    TEXT,
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);
CREATE INDEX IF NOT EXISTS idx_bo_tg_tokens_order ON bo_telegram_tokens (order_code, used_at);
SQL;
}
