<?php

declare(strict_types=1);

namespace App\BackOffice;

use RuntimeException;

/** Error de negocio del back-office con código HTTP y código de error estable para el consumidor (n8n). */
final class BoException extends RuntimeException
{
    /** @param array<string, string> $details campo => motivo */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public static function validation(array $details): self
    {
        return new self(422, 'validation_failed', 'El payload no cumple el contrato.', $details);
    }

    public static function notFound(string $what): self
    {
        return new self(404, 'not_found', $what . ' no existe en el back-office.');
    }

    public static function conflict(string $code, string $message): self
    {
        return new self(409, $code, $message);
    }
}
