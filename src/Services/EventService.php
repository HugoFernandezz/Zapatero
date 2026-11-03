<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Registro de eventos de negocio (tabla events). Cada evento recibe un event_id único que sirve de
 * clave de idempotencia a los sistemas que lo consumen (n8n, back-office). Datos exclusivamente de prueba.
 */
final class EventService
{
    /** Versión actual del contrato del payload (ver docs/contrato-integracion.md). */
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly PDO $pdo) {}

    /** @return string event_id del evento registrado */
    public function record(string $type, array $payload, ?string $sessionId, ?int $userId = null): string
    {
        $eventId = 'evt_' . bin2hex(random_bytes(16));
        $statement = $this->pdo->prepare(
            'INSERT INTO events (event_id, schema_version, type, session_id, user_id, payload)
             VALUES (:event_id, :version, :type, :session_id, :user_id, :payload)'
        );
        $statement->execute([
            'event_id' => $eventId,
            'version' => self::SCHEMA_VERSION,
            'type' => $type,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $eventId;
    }
}
