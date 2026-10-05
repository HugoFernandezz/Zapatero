<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/** Adaptador mínimo al esquema events de M5; los datos son exclusivamente del prototipo. */
final class EventService
{
    public function __construct(private readonly PDO $pdo) {}

    public function record(string $type, array $payload, ?string $sessionId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO events (type, session_id, payload) VALUES (:type, :session_id, :payload)'
        );
        $statement->execute([
            'type' => $type,
            'session_id' => $sessionId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
    }
}
