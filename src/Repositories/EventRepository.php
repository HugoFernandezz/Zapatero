<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Consulta de solo lectura sobre la tabla events (back-office y exportación para otros sistemas). */
final class EventRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @param array{type?: string, q?: string, from?: string, to?: string} $filters
     * @return list<array{id: int, type: string, occurred_at: string, session_id: ?string, user_id: ?int, payload: string}>
     */
    public function search(array $filters, int $limit, int $offset = 0): array
    {
        [$where, $params] = $this->where($filters);
        $statement = $this->pdo->prepare(
            'SELECT id, type, occurred_at, session_id, user_id, payload FROM events ' . $where
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Lectura incremental para integraciones (cursor por id autoincremental, orden ascendente).
     * Pide limit + 1 filas para saber si hay más sin una segunda consulta.
     *
     * @return list<array<string, mixed>>
     */
    public function after(int $afterId, int $limit, string $type = ''): array
    {
        $sql = 'SELECT id, event_id, schema_version, type, occurred_at, session_id, user_id, payload FROM events WHERE id > :after';
        if ($type !== '') {
            $sql .= ' AND type = :type';
        }
        $statement = $this->pdo->prepare($sql . ' ORDER BY id ASC LIMIT :limit');
        $statement->bindValue(':after', $afterId, PDO::PARAM_INT);
        if ($type !== '') {
            $statement->bindValue(':type', $type);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->where($filters);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM events ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, int> tipo => nº de eventos (sin filtros) */
    public function countsByType(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT type, COUNT(*) AS n FROM events GROUP BY type ORDER BY type')->fetchAll() as $row) {
            $counts[(string) $row['type']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        if (($filters['type'] ?? '') !== '') {
            $where[] = 'type = :type';
            $params[':type'] = $filters['type'];
        }
        if (($filters['q'] ?? '') !== '') {
            // Busca en el JSON del payload (p. ej. un código de pedido) y en la sesión.
            $where[] = "(payload LIKE :q1 ESCAPE '\\' OR session_id LIKE :q2 ESCAPE '\\')";
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'occurred_at >= :from';
            $params[':from'] = $filters['from'] . 'T00:00:00Z';
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'occurred_at <= :to';
            $params[':to'] = $filters['to'] . 'T23:59:59Z';
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }
}
