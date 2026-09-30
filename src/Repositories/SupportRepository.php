<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SupportRepository
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    public function findOrderByCode(string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, status
             FROM orders
             WHERE code = :code'
        );

        $statement->execute([
            'code' => $code,
        ]);

        $order = $statement->fetch();

        return $order !== false ? $order : null;
    }

    public function createTicket(
        ?int $orderId,
        string $subject,
        string $message
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO support_tickets
                (order_id, subject, message)
             VALUES
                (:order_id, :subject, :message)'
        );

        $statement->execute([
            'order_id' => $orderId,
            'subject' => $subject,
            'message' => $message,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function allTickets(): array
    {
        $statement = $this->pdo->query(
            'SELECT
                st.id,
                st.order_id,
                st.subject,
                st.message,
                st.status,
                st.created_at,
                o.code AS order_code,
                o.status AS order_status
             FROM support_tickets st
             LEFT JOIN orders o ON o.id = st.order_id
             ORDER BY st.created_at DESC, st.id DESC'
        );

        return $statement->fetchAll();
    }

    public function findTicket(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                st.id,
                st.order_id,
                st.subject,
                st.message,
                st.status,
                st.created_at,
                o.code AS order_code,
                o.status AS order_status
             FROM support_tickets st
             LEFT JOIN orders o ON o.id = st.order_id
             WHERE st.id = :id'
        );

        $statement->execute([
            'id' => $id,
        ]);

        $ticket = $statement->fetch();

        return $ticket !== false ? $ticket : null;
    }

    public function setOrderIncident(int $orderId): ?array
    {
        $order = $this->findOrderById($orderId);

        if ($order === null) {
            return null;
        }

        $allowedStatuses = [
            'paid_simulated',
            'pending_preparation',
            'shipped',
        ];

        if (!in_array($order['status'], $allowedStatuses, true)) {
            return null;
        }

        $statement = $this->pdo->prepare(
            'UPDATE orders
             SET status = :status
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'incident',
            'id' => $orderId,
        ]);

        $order['status'] = 'incident';

        return $order;
    }

    public function resolveIncident(int $orderId): ?array
    {
        $order = $this->findOrderById($orderId);

        if ($order === null || $order['status'] !== 'incident') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'UPDATE orders
             SET status = :status
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'pending_preparation',
            'id' => $orderId,
        ]);

        $order['status'] = 'pending_preparation';

        return $order;
    }

    public function closeTicket(int $ticketId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE support_tickets
             SET status = :status
             WHERE id = :id'
        );

        $statement->execute([
            'status' => 'closed',
            'id' => $ticketId,
        ]);

        return $statement->rowCount() > 0;
    }

    private function findOrderById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, code, status
             FROM orders
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $id,
        ]);

        $order = $statement->fetch();

        return $order !== false ? $order : null;
    }
}