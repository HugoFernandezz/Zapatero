<?php

declare(strict_types=1);

namespace App\Support;

/** Token CSRF de sesión (mismo token `_csrf` que ya usan carrito y checkout). */
final class Csrf
{
    public static function token(): string
    {
        return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function valid(array $body): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals((string) $_SESSION['_csrf'], (string) ($body['_csrf'] ?? ''));
    }
}
