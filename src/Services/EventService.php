<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Adaptador mínimo al esquema events de M5; los datos son exclusivamente del prototipo. */
final class EventService
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(string $type, array $payload, ?string $sessionId, ?int $userId = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO events (type, session_id, user_id, payload) VALUES (:type, :session_id, :user_id, :payload)'
        );
        $statement->execute([
            'type' => $type,
            // Nunca se guarda el id de sesión real (sería un secreto si se exporta): solo un identificador derivado.
            'session_id' => $sessionId === null ? null : substr(hash('sha256', $sessionId), 0, 16),
            'user_id' => $userId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
