<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Acceso a datos de pedidos, líneas, pagos y eventos asociados. */
final class OrderRepository
{
    /** Los códigos de descuento viven en CartService; aquí se asegura su fila para guardar la FK. */
    private const DISCOUNTS = ['BIENVENIDA10' => ['percent', 10], 'ZAP5' => ['fixed', 500], 'CLIENTE10' => ['percent', 10]];

    private const JSON_NAME = "json_extract(o.shipping_address, '\$.full_name')";
    private const JSON_EMAIL = "json_extract(o.shipping_address, '\$.email')";

    public function __construct(private readonly PDO $pdo) {}

    /** Siguiente identificador ZAP-AAAAMMDD-XXXX del día indicado (formato Ymd). Llamar dentro de la transacción. */
    public function nextCode(string $day): string
    {
        $prefix = 'ZAP-' . $day . '-';
        $statement = $this->pdo->prepare('SELECT MAX(CAST(substr(code, 14) AS INTEGER)) FROM orders WHERE code LIKE :prefix');
        $statement->execute(['prefix' => $prefix . '%']);

        return sprintf('%s%04d', $prefix, (int) $statement->fetchColumn() + 1);
    }

    public function discountCodeId(?string $code): ?int
    {
        if ($code === null || !isset(self::DISCOUNTS[$code])) {
            return null;
        }
        [$type, $value] = self::DISCOUNTS[$code];
        $this->pdo->prepare('INSERT OR IGNORE INTO discount_codes (code, type, value) VALUES (:code, :type, :value)')
            ->execute(['code' => $code, 'type' => $type, 'value' => $value]);
        $statement = $this->pdo->prepare('SELECT id FROM discount_codes WHERE code = :code');
        $statement->execute(['code' => $code]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function insertOrder(array $o): int
    {
        $this->pdo->prepare(
            'INSERT INTO orders (code, user_id, discount_code_id, status, subtotal_cents, discount_cents, shipping_cents, tax_cents, total_cents, shipping_address)
             VALUES (:code, :user_id, :discount_code_id, :status, :subtotal, :discount, :shipping, :tax, :total, :address)'
        )->execute([
            'code' => $o['code'],
            'user_id' => $o['user_id'] ?? null,
            'discount_code_id' => $o['discount_code_id'],
            'status' => $o['status'],
            'subtotal' => $o['subtotal_cents'],
            'discount' => $o['discount_cents'],
            'shipping' => $o['shipping_cents'],
            'tax' => $o['tax_cents'],
            'total' => $o['total_cents'],
            'address' => $o['shipping_address'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function insertItem(int $orderId, int $variantId, int $quantity, int $unitPriceCents): void
    {
        $this->pdo->prepare(
            'INSERT INTO order_items (order_id, variant_id, quantity, unit_price_cents) VALUES (:order, :variant, :qty, :price)'
        )->execute(['order' => $orderId, 'variant' => $variantId, 'qty' => $quantity, 'price' => $unitPriceCents]);
    }

    public function insertPayment(int $orderId, string $method, string $status, string $reference, ?string $last4): void
    {
        $this->pdo->prepare(
            'INSERT INTO payments (order_id, method, status, reference, card_last4) VALUES (:order, :method, :status, :reference, :last4)'
        )->execute(['order' => $orderId, 'method' => $method, 'status' => $status, 'reference' => $reference, 'last4' => $last4]);
    }

    public function updateStatus(int $orderId, string $status): void
    {
        $this->pdo->prepare('UPDATE orders SET status = :status WHERE id = :id')
            ->execute(['status' => $status, 'id' => $orderId]);
    }

    /** Descuenta stock solo si queda suficiente (evita stock negativo ante compras simultáneas). */
    public function decrementStock(int $variantId, int $quantity): bool
    {
        $statement = $this->pdo->prepare('UPDATE product_variants SET stock = stock - :qty WHERE id = :id AND stock >= :min');
        $statement->execute(['qty' => $quantity, 'id' => $variantId, 'min' => $quantity]);

        return $statement->rowCount() === 1;
    }

    /** Devuelve al stock las unidades de todas las líneas del pedido. */
    public function restock(int $orderId): void
    {
        $this->pdo->prepare(
            'UPDATE product_variants
             SET stock = stock + (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = :order AND variant_id = product_variants.id)
             WHERE id IN (SELECT variant_id FROM order_items WHERE order_id = :order2)'
        )->execute(['order' => $orderId, 'order2' => $orderId]);
    }

    /** Vacía el carrito guardado del usuario (tras un pago aprobado). */
    public function clearUserCart(int $userId): void
    {
        $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = :user')->execute(['user' => $userId]);
    }

    /** Pedidos del cliente, del más reciente al más antiguo. */
    public function forUser(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.code, o.status, o.total_cents, o.created_at,
                    (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS units
             FROM orders o WHERE o.user_id = :user ORDER BY o.id DESC LIMIT 100'
        );
        $statement->execute(['user' => $userId]);

        return $statement->fetchAll();
    }

    public function find(string $code): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.*, dc.code AS discount_code
             FROM orders o LEFT JOIN discount_codes dc ON dc.id = o.discount_code_id
             WHERE o.code = :code'
        );
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    public function items(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT oi.id, oi.variant_id, oi.quantity, oi.unit_price_cents, pv.size_eu, pv.sku, p.name, p.slug
             FROM order_items oi
             JOIN product_variants pv ON pv.id = oi.variant_id
             JOIN products p ON p.id = pv.product_id
             WHERE oi.order_id = :order ORDER BY oi.id'
        );
        $statement->execute(['order' => $orderId]);

        return $statement->fetchAll();
    }

    public function latestPayment(int $orderId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE order_id = :order ORDER BY id DESC LIMIT 1');
        $statement->execute(['order' => $orderId]);
        $row = $statement->fetch();

        return $row ?: null;
    }

    /** Eventos cuyo payload referencia el pedido (order.created, payment.simulated, cambios de estado, factura…). */
    public function events(string $code): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, type, occurred_at, user_id, payload FROM events
             WHERE json_extract(payload, '\$.order_code') = :code ORDER BY id ASC"
        );
        $statement->execute(['code' => $code]);

        return $statement->fetchAll();
    }

    public function search(?string $status, string $query, int $limit, int $offset): array
    {
        [$where, $params] = $this->filters($status, $query);
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.code, o.status, o.total_cents, o.created_at, '
            . self::JSON_NAME . ' AS customer_name, ' . self::JSON_EMAIL . ' AS customer_email, '
            . '(SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE order_id = o.id) AS units '
            . 'FROM orders o ' . $where . ' ORDER BY o.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value);
        }
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function count(?string $status, string $query): int
    {
        [$where, $params] = $this->filters($status, $query);
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM orders o ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,int> estado => nº de pedidos */
    public function statusCounts(): array
    {
        $counts = [];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM orders GROUP BY status')->fetchAll() as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @return array{0: string, 1: array<string,string>} */
    private function filters(?string $status, string $query): array
    {
        $where = [];
        $params = [];
        if ($status !== null && $status !== '') {
            $where[] = 'o.status = :status';
            $params[':status'] = $status;
        }
        if ($query !== '') {
            $esc = "ESCAPE '\\'";
            $where[] = '(o.code LIKE :q1 ' . $esc . ' OR ' . self::JSON_EMAIL . ' LIKE :q2 ' . $esc
                . ' OR ' . self::JSON_NAME . ' LIKE :q3 ' . $esc . ')';
            $like = '%' . addcslashes($query, '%_\\') . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    /** Solicitudes de soporte / postventa de un pedido (tabla support_tickets). */
    public function tickets(int $orderId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, subject, message, status, created_at FROM support_tickets WHERE order_id = :id ORDER BY id DESC'
        );
        $statement->execute(['id' => $orderId]);

        return $statement->fetchAll();
    }

    public function countTickets(int $orderId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE order_id = :id');
        $statement->execute(['id' => $orderId]);

        return (int) $statement->fetchColumn();
    }

    public function insertTicket(int $orderId, string $subject, string $message): int
    {
        $this->pdo->prepare('INSERT INTO support_tickets (order_id, subject, message) VALUES (:order_id, :subject, :message)')
            ->execute(['order_id' => $orderId, 'subject' => $subject, 'message' => $message]);

        return (int) $this->pdo->lastInsertId();
    }
}
