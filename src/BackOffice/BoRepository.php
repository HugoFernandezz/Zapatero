<?php

declare(strict_types=1);

namespace App\BackOffice;

use PDO;

/** Acceso SQL a la base del back-office. Sin reglas de negocio (están en BoService). */
final class BoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function begin(): void { $this->pdo->beginTransaction(); }

    public function commit(): void { $this->pdo->commit(); }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();

        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);

        return $st->fetchAll();
    }

    // ---------- idempotencia y log ----------

    public function isProcessed(string $eventId): bool
    {
        return $this->one('SELECT 1 AS x FROM bo_processed_events WHERE event_id = :e', ['e' => $eventId]) !== null;
    }

    public function markProcessed(string $eventId, string $type): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO bo_processed_events (event_id, event_type) VALUES (:e, :t)')
            ->execute(['e' => $eventId, 't' => $type]);
    }

    public function log(?string $eventId, ?string $type, string $status, ?string $workflow = null, ?string $execution = null,
        ?string $errorCode = null, ?string $message = null, ?string $entityType = null, ?string $entityRef = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO bo_integration_log (event_id, event_type, workflow, execution_id, status, error_code, message, entity_type, entity_ref)
             VALUES (:e, :t, :w, :x, :s, :c, :m, :et, :er)'
        )->execute([
            'e' => $eventId, 't' => $type, 'w' => $workflow, 'x' => $execution, 's' => $status,
            'c' => $errorCode, 'm' => $message !== null ? mb_substr($message, 0, 500) : null, 'et' => $entityType, 'er' => $entityRef,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listLog(string $status, int $limit, int $offset): array
    {
        $where = $status !== '' ? 'WHERE status = :s' : '';
        $st = $this->pdo->prepare("SELECT * FROM bo_integration_log $where ORDER BY id DESC LIMIT :l OFFSET :o");
        if ($status !== '') {
            $st->bindValue(':s', $status);
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    public function countLog(string $status): int
    {
        return $status !== ''
            ? (int) ($this->one('SELECT COUNT(*) AS n FROM bo_integration_log WHERE status = :s', ['s' => $status])['n'] ?? 0)
            : (int) ($this->one('SELECT COUNT(*) AS n FROM bo_integration_log')['n'] ?? 0);
    }

    /** @return array<string, int> estado => nº */
    public function logCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT status, COUNT(*) AS n FROM bo_integration_log GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    // ---------- clientes ----------

    public function upsertCustomer(string $email, string $name, ?string $city, ?string $province): int
    {
        $row = $this->one('SELECT id FROM bo_customers WHERE email = :e', ['e' => $email]);
        if ($row !== null) {
            $this->pdo->prepare('UPDATE bo_customers SET full_name = :n, city = :c, province = :p, updated_at = :u WHERE id = :id')
                ->execute(['n' => $name, 'c' => $city, 'p' => $province, 'u' => self::now(), 'id' => $row['id']]);

            return (int) $row['id'];
        }
        $this->pdo->prepare('INSERT INTO bo_customers (email, full_name, city, province) VALUES (:e, :n, :c, :p)')
            ->execute(['e' => $email, 'n' => $name, 'c' => $city, 'p' => $province]);

        return (int) $this->pdo->lastInsertId();
    }

    public function customer(int $id): ?array
    {
        return $this->one('SELECT * FROM bo_customers WHERE id = :id', ['id' => $id]);
    }

    public function setTelegramChat(int $customerId, string $chatId): void
    {
        $this->pdo->prepare('UPDATE bo_customers SET telegram_chat_id = :t, updated_at = :u WHERE id = :id')
            ->execute(['t' => $chatId, 'u' => self::now(), 'id' => $customerId]);
    }

    // ---------- tokens de vinculación de Telegram ----------

    /** Crea un token nuevo y caduca los anteriores aún sin usar de ese pedido (solo hay uno activo). */
    public function createTelegramToken(string $tokenHash, string $orderCode, string $expiresAt): void
    {
        $now = self::now();
        $this->pdo->prepare('UPDATE bo_telegram_tokens SET expires_at = :n1 WHERE order_code = :c AND used_at IS NULL AND expires_at > :n2')
            ->execute(['n1' => $now, 'c' => $orderCode, 'n2' => $now]);
        $this->pdo->prepare('INSERT INTO bo_telegram_tokens (token_hash, order_code, expires_at, created_at) VALUES (:h, :c, :e, :t)')
            ->execute(['h' => $tokenHash, 'c' => $orderCode, 'e' => $expiresAt, 't' => $now]);
    }

    /** @return array<string, mixed>|null */
    public function telegramToken(string $tokenHash): ?array
    {
        return $this->one('SELECT * FROM bo_telegram_tokens WHERE token_hash = :h', ['h' => $tokenHash]);
    }

    /** Marca el token como usado. Devuelve false si otro proceso lo usó antes (consumo atómico). */
    public function consumeTelegramToken(int $id): bool
    {
        $st = $this->pdo->prepare('UPDATE bo_telegram_tokens SET used_at = :t WHERE id = :id AND used_at IS NULL');
        $st->execute(['t' => self::now(), 'id' => $id]);

        return $st->rowCount() === 1;
    }

    // ---------- pedidos ----------

    public function order(string $code): ?array
    {
        return $this->one(
            'SELECT o.*, c.full_name AS customer_name, c.email AS customer_email
             FROM bo_orders o JOIN bo_customers c ON c.id = o.customer_id WHERE o.order_code = :c',
            ['c' => $code]
        );
    }

    /** @return array{0: int, 1: bool} [id, creado] */
    public function upsertOrder(array $o): array
    {
        $existing = $this->one('SELECT id FROM bo_orders WHERE order_code = :c', ['c' => $o['order_code']]);
        $params = [
            'customer_id' => $o['customer_id'], 'status' => $o['status'], 'currency' => $o['currency'],
            'subtotal' => $o['subtotal_cents'], 'discount' => $o['discount_cents'], 'shipping' => $o['shipping_cents'],
            'tax' => $o['tax_cents'], 'total' => $o['total_cents'], 'pstatus' => $o['payment_status'], 'pref' => $o['payment_reference'],
            'ordered_at' => $o['ordered_at'], 'event' => $o['event_id'], 'now' => self::now(), 'status_at' => $o['status_updated_at'],
        ];
        if ($existing !== null) {
            $this->pdo->prepare(
                'UPDATE bo_orders SET customer_id=:customer_id, status=:status, currency=:currency, subtotal_cents=:subtotal,
                 discount_cents=:discount, shipping_cents=:shipping, tax_cents=:tax, total_cents=:total, payment_status=:pstatus,
                 payment_reference=:pref, ordered_at=:ordered_at, last_event_id=:event, synced_at=:now, status_updated_at=:status_at WHERE id=:id'
            )->execute($params + ['id' => $existing['id']]);

            return [(int) $existing['id'], false];
        }
        $this->pdo->prepare(
            'INSERT INTO bo_orders (order_code, customer_id, status, currency, subtotal_cents, discount_cents, shipping_cents, tax_cents,
             total_cents, payment_status, payment_reference, ordered_at, last_event_id, synced_at, status_updated_at)
             VALUES (:code, :customer_id, :status, :currency, :subtotal, :discount, :shipping, :tax, :total, :pstatus, :pref,
             :ordered_at, :event, :now, :status_at)'
        )->execute($params + ['code' => $o['order_code']]);

        return [(int) $this->pdo->lastInsertId(), true];
    }

    public function replaceLines(int $orderId, array $items): void
    {
        $this->pdo->prepare('DELETE FROM bo_order_lines WHERE order_id = :id')->execute(['id' => $orderId]);
        $st = $this->pdo->prepare(
            'INSERT INTO bo_order_lines (order_id, sku, name, size_eu, quantity, unit_price_cents) VALUES (:o, :s, :n, :z, :q, :p)'
        );
        foreach ($items as $i) {
            $st->execute(['o' => $orderId, 's' => $i['sku'], 'n' => $i['name'], 'z' => $i['size_eu'], 'q' => $i['quantity'], 'p' => $i['unit_price_cents']]);
        }
    }

    public function lines(int $orderId): array
    {
        return $this->all('SELECT sku, name, size_eu, quantity, unit_price_cents FROM bo_order_lines WHERE order_id = :id ORDER BY id', ['id' => $orderId]);
    }

    public function setOrderStatus(string $code, string $status, string $occurredAt, string $eventId): void
    {
        $this->pdo->prepare(
            'UPDATE bo_orders SET status = :s, status_updated_at = :t, last_event_id = :e WHERE order_code = :c'
        )->execute(['s' => $status, 't' => $occurredAt, 'e' => $eventId, 'c' => $code]);
    }

    public function listOrders(string $status, string $q, int $limit, int $offset): array
    {
        [$where, $p] = $this->orderFilter($status, $q);
        $st = $this->pdo->prepare(
            "SELECT o.*, c.full_name AS customer_name, c.email AS customer_email FROM bo_orders o
             JOIN bo_customers c ON c.id = o.customer_id $where ORDER BY o.id DESC LIMIT :l OFFSET :o"
        );
        foreach ($p as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    public function countOrders(string $status, string $q): int
    {
        [$where, $p] = $this->orderFilter($status, $q);

        return (int) ($this->one("SELECT COUNT(*) AS n FROM bo_orders o JOIN bo_customers c ON c.id = o.customer_id $where", $p)['n'] ?? 0);
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function orderFilter(string $status, string $q): array
    {
        $w = [];
        $p = [];
        if ($status !== '') {
            $w[] = 'o.status = :status';
            $p[':status'] = $status;
        }
        if ($q !== '') {
            $w[] = "(o.order_code LIKE :q1 ESCAPE '\\' OR c.email LIKE :q2 ESCAPE '\\' OR c.full_name LIKE :q3 ESCAPE '\\')";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $p[':q1'] = $like;
            $p[':q2'] = $like;
            $p[':q3'] = $like;
        }

        return [$w === [] ? '' : 'WHERE ' . implode(' AND ', $w), $p];
    }

    public function ordersForCustomer(int $customerId, int $limit = 20): array
    {
        return $this->all(
            'SELECT order_code, status, total_cents, ordered_at FROM bo_orders WHERE customer_id = :c ORDER BY id DESC LIMIT ' . (int) $limit,
            ['c' => $customerId]
        );
    }

    public function orderStatusCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT status, COUNT(*) AS n FROM bo_orders GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    // ---------- tickets ----------

    public function ticket(string $ref): ?array
    {
        return $this->one(
            'SELECT t.*, o.order_code FROM bo_tickets t JOIN bo_orders o ON o.id = t.order_id WHERE t.ticket_ref = :r',
            ['r' => $ref]
        );
    }

    public function insertTicket(array $t): void
    {
        $this->pdo->prepare(
            'INSERT INTO bo_tickets (ticket_ref, kind, order_id, customer_id, source_event_id, subject, message, category, priority, created_at)
             VALUES (:ref, :kind, :order_id, :customer_id, :event, :subject, :message, :category, :priority, :created_at)'
        )->execute($t);
    }

    public function updateTicket(string $ref, array $fields): void
    {
        $sets = [];
        $params = ['ref' => $ref];
        foreach (['status', 'priority', 'category', 'resolved_at'] as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "$col = :$col";
                $params[$col] = $fields[$col];
            }
        }
        $sets[] = 'updated_at = :updated_at';
        $params['updated_at'] = self::now();
        $this->pdo->prepare('UPDATE bo_tickets SET ' . implode(', ', $sets) . ' WHERE ticket_ref = :ref')->execute($params);
    }

    public function ticketsForOrder(int $orderId): array
    {
        return $this->all(
            'SELECT ticket_ref, kind, subject, message, category, priority, status, created_at, resolved_at
             FROM bo_tickets WHERE order_id = :o ORDER BY id DESC',
            ['o' => $orderId]
        );
    }

    public function ticketsForCustomer(int $customerId, int $limit = 20): array
    {
        return $this->all(
            'SELECT t.ticket_ref, t.kind, t.subject, t.status, t.priority, t.created_at, t.resolved_at, o.order_code
             FROM bo_tickets t JOIN bo_orders o ON o.id = t.order_id WHERE t.customer_id = :c ORDER BY t.id DESC LIMIT ' . (int) $limit,
            ['c' => $customerId]
        );
    }

    public function listTickets(string $status, int $limit, int $offset): array
    {
        $where = $status !== '' ? 'WHERE t.status = :s' : '';
        $st = $this->pdo->prepare(
            "SELECT t.*, o.order_code, c.full_name AS customer_name FROM bo_tickets t
             JOIN bo_orders o ON o.id = t.order_id JOIN bo_customers c ON c.id = t.customer_id
             $where ORDER BY t.id DESC LIMIT :l OFFSET :o"
        );
        if ($status !== '') {
            $st->bindValue(':s', $status);
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    public function ticketStatusCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT status, COUNT(*) AS n FROM bo_tickets GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    // ---------- pendientes de sincronización ----------

    public function addPending(string $eventId, string $type, string $orderCode, array $payload, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO bo_pending_sync (event_id, event_type, order_code, payload, reason) VALUES (:e, :t, :o, :p, :r)'
        )->execute([
            'e' => $eventId, 't' => $type, 'o' => $orderCode,
            'p' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'r' => $reason,
        ]);
    }

    public function pendingForOrder(string $code): array
    {
        return $this->all("SELECT * FROM bo_pending_sync WHERE order_code = :c AND status = 'pending' ORDER BY id", ['c' => $code]);
    }

    public function markPendingSynced(int $id): void
    {
        $this->pdo->prepare("UPDATE bo_pending_sync SET status = 'synced', synced_at = :t WHERE id = :id")
            ->execute(['t' => self::now(), 'id' => $id]);
    }

    public function listPending(int $limit = 100): array
    {
        return $this->all("SELECT id, event_id, event_type, order_code, reason, status, created_at, synced_at FROM bo_pending_sync ORDER BY id DESC LIMIT " . (int) $limit);
    }

    public function pendingCount(): int
    {
        return (int) ($this->one("SELECT COUNT(*) AS n FROM bo_pending_sync WHERE status = 'pending'")['n'] ?? 0);
    }

    // ---------- acciones del agente ----------

    public function insertAgentAction(array $a): int
    {
        $this->pdo->prepare(
            'INSERT INTO bo_agent_actions (ticket_ref, order_code, case_type, consulted_data, action_type, action_payload,
             justification, confidence, priority, risks, model)
             VALUES (:ticket_ref, :order_code, :case_type, :consulted, :action_type, :payload, :justification, :confidence, :priority, :risks, :model)'
        )->execute($a);

        return (int) $this->pdo->lastInsertId();
    }

    public function agentAction(int $id): ?array
    {
        return $this->one(
            'SELECT a.*, c.telegram_chat_id FROM bo_agent_actions a
             LEFT JOIN bo_orders o ON o.order_code = a.order_code LEFT JOIN bo_customers c ON c.id = o.customer_id WHERE a.id = :id',
            ['id' => $id]
        );
    }

    public function listAgentActions(string $status, int $limit, int $offset = 0): array
    {
        $where = $status !== '' ? 'WHERE a.status = :s' : '';
        $st = $this->pdo->prepare(
            "SELECT a.*, c.telegram_chat_id FROM bo_agent_actions a
             LEFT JOIN bo_orders o ON o.order_code = a.order_code LEFT JOIN bo_customers c ON c.id = o.customer_id
             $where ORDER BY a.id DESC LIMIT :l OFFSET :o"
        );
        if ($status !== '') {
            $st->bindValue(':s', $status);
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    public function agentStatusCounts(): array
    {
        $out = [];
        foreach ($this->all('SELECT status, COUNT(*) AS n FROM bo_agent_actions GROUP BY status') as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    public function decideAgentAction(int $id, string $status, string $by, string $note): void
    {
        $this->pdo->prepare('UPDATE bo_agent_actions SET status = :s, decided_at = :t, decided_by = :b, decision_note = :n WHERE id = :id')
            ->execute(['s' => $status, 't' => self::now(), 'b' => $by, 'n' => $note, 'id' => $id]);
    }

    public function executeAgentAction(int $id, string $status, string $note): void
    {
        $this->pdo->prepare('UPDATE bo_agent_actions SET status = :s, executed_at = :t, execution_note = :n WHERE id = :id')
            ->execute(['s' => $status, 't' => self::now(), 'n' => $note, 'id' => $id]);
    }

    // ---------- métricas (solo lectura, para el cuadro de mando) ----------

    /** @return array{total: int, cancelled: int, amount_cents: int} */
    public function orderTotals(): array
    {
        $r = $this->one(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status <> 'cancelled' THEN total_cents ELSE 0 END) AS amount FROM bo_orders"
        ) ?? [];

        return ['total' => (int) ($r['total'] ?? 0), 'cancelled' => (int) ($r['cancelled'] ?? 0), 'amount_cents' => (int) ($r['amount'] ?? 0)];
    }

    /** @return list<array{day: string, orders: int, cents: int}> días con pedidos (importe sin cancelados) */
    public function ordersPerDay(): array
    {
        $rows = $this->all(
            "SELECT substr(ordered_at, 1, 10) AS day, COUNT(*) AS orders,
                    SUM(CASE WHEN status <> 'cancelled' THEN total_cents ELSE 0 END) AS cents
             FROM bo_orders GROUP BY day ORDER BY day"
        );

        return array_map(static fn (array $r): array => ['day' => (string) $r['day'], 'orders' => (int) $r['orders'], 'cents' => (int) ($r['cents'] ?? 0)], $rows);
    }

    /** @return list<array{name: string, units: int}> */
    public function topProducts(int $limit = 5): array
    {
        $rows = $this->all(
            "SELECT l.name AS name, SUM(l.quantity) AS units FROM bo_order_lines l JOIN bo_orders o ON o.id = l.order_id
             WHERE o.status <> 'cancelled' GROUP BY l.name ORDER BY units DESC, l.name LIMIT " . (int) $limit
        );

        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'units' => (int) $r['units']], $rows);
    }

    /** @return array<string, mixed> */
    public function ticketStats(): array
    {
        $r = $this->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) AS resolved,
                    SUM(CASE WHEN kind = 'support' THEN 1 ELSE 0 END) AS support,
                    SUM(CASE WHEN kind = 'incident' THEN 1 ELSE 0 END) AS incident,
                    AVG(CASE WHEN resolved_at IS NOT NULL THEN (julianday(resolved_at) - julianday(created_at)) * 1440.0 END) AS avg_minutes
             FROM bo_tickets"
        ) ?? [];

        return [
            'total' => (int) ($r['total'] ?? 0), 'open' => (int) ($r['open'] ?? 0), 'in_progress' => (int) ($r['in_progress'] ?? 0),
            'resolved' => (int) ($r['resolved'] ?? 0), 'support' => (int) ($r['support'] ?? 0), 'incident' => (int) ($r['incident'] ?? 0),
            'avg_minutes' => $r['avg_minutes'] !== null ? (float) $r['avg_minutes'] : null,
        ];
    }

    /** @return list<array{code: string, status: string, n: int}> */
    public function logErrorsByType(int $limit = 8): array
    {
        $rows = $this->all(
            "SELECT COALESCE(error_code, '(sin código)') AS code, status, COUNT(*) AS n FROM bo_integration_log
             WHERE status IN ('rejected', 'error') GROUP BY code, status ORDER BY n DESC, code LIMIT " . (int) $limit
        );

        return array_map(static fn (array $r): array => ['code' => (string) $r['code'], 'status' => (string) $r['status'], 'n' => (int) $r['n']], $rows);
    }

    /** @return array<string, mixed> */
    public function agentStats(): array
    {
        $r = $this->one(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status IN ('approved', 'executed', 'executed_simulated', 'execution_failed') THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN status = 'executed' THEN 1 ELSE 0 END) AS executed,
                    SUM(CASE WHEN status = 'executed_simulated' THEN 1 ELSE 0 END) AS simulated,
                    SUM(CASE WHEN confidence = 0 THEN 1 ELSE 0 END) AS failsafe,
                    AVG(CASE WHEN confidence > 0 THEN confidence END) AS avg_confidence
             FROM bo_agent_actions"
        ) ?? [];
        $by = [];
        foreach ($this->all('SELECT action_type, COUNT(*) AS n FROM bo_agent_actions GROUP BY action_type ORDER BY n DESC') as $row) {
            $by[(string) $row['action_type']] = (int) $row['n'];
        }

        return [
            'total' => (int) ($r['total'] ?? 0), 'pending' => (int) ($r['pending'] ?? 0), 'approved' => (int) ($r['approved'] ?? 0),
            'rejected' => (int) ($r['rejected'] ?? 0), 'executed' => (int) ($r['executed'] ?? 0), 'simulated' => (int) ($r['simulated'] ?? 0),
            'failsafe' => (int) ($r['failsafe'] ?? 0), 'avg_confidence' => $r['avg_confidence'] !== null ? (float) $r['avg_confidence'] : null,
            'by_action' => $by,
        ];
    }

    // ---------- estado de sincronización ----------

    public function state(string $key): ?string
    {
        $row = $this->one('SELECT value FROM bo_sync_state WHERE key = :k', ['k' => $key]);

        return $row === null ? null : (string) $row['value'];
    }

    public function setState(string $key, string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO bo_sync_state (key, value, updated_at) VALUES (:k, :v, :t)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at'
        )->execute(['k' => $key, 'v' => $value, 't' => self::now()]);
    }
}
