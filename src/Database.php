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

        // Si el fichero no existía se crea el esquema (así el servidor no necesita SSH).
        if ($isNew) {
            $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
        }

        return $pdo;
    }
}
