<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class EventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $type,
        ?string $sessionId,
        ?int $userId,
        array $payload
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO events (type, session_id, user_id, payload)
             VALUES (:type, :session_id, :user_id, :payload)'
        );

        $statement->execute([
            'type' => $type,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function all(
        ?string $type = null,
        ?string $since = null
    ): array {
        $sql = '
            SELECT id, type, occurred_at, session_id, user_id, payload
            FROM events
        ';

        $conditions = [];
        $params = [];

        if ($type !== null && $type !== '') {
            $conditions[] = 'type = :type';
            $params['type'] = $type;
        }

        if ($since !== null && $since !== '') {
            $conditions[] = 'occurred_at >= :since';
            $params['since'] = $since;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY occurred_at DESC, id DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }
}