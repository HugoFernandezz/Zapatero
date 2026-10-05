<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EventRepository;

final class EventService
{
    public function __construct(
        private readonly EventRepository $events
    ) {
    }

    public function record(
        string $type,
        array $payload = [],
        ?string $sessionId = null,
        ?int $userId = null
    ): int {
        return $this->events->create(
            $type,
            $sessionId,
            $userId,
            $payload
        );
    }

    public function all(
        ?string $type = null,
        ?string $since = null
    ): array {
        return $this->events->all(
            $type,
            $since
        );
    }
}