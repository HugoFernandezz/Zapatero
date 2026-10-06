<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Consultas del catálogo necesarias para construir y validar el carrito. */
final class CartRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function variant(int $variantId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pv.id AS variant_id, pv.product_id, pv.size_eu, pv.stock,
                    p.name, p.slug, p.price_cents, p.image
             FROM product_variants pv JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :id'
        );
        $statement->execute(['id' => $variantId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    /** Devuelve todas las líneas actuales; las cantidades proceden de la sesión. */
    public function variants(array $quantities): array
    {
        $items = [];
        foreach ($quantities as $id => $quantity) {
            $row = $this->variant((int) $id);
            if ($row !== null) {
                $row['quantity'] = (int) $quantity;
                $items[] = $row;
            }
        }
        return $items;
    }

    /** @return array<int,int> variant_id => cantidad guardada para el usuario */
    public function userCart(int $userId): array
    {
        $statement = $this->pdo->prepare('SELECT variant_id, quantity FROM cart_items WHERE user_id = :user ORDER BY rowid');
        $statement->execute(['user' => $userId]);
        $cart = [];
        foreach ($statement->fetchAll() as $row) {
            $cart[(int) $row['variant_id']] = (int) $row['quantity'];
        }

        return $cart;
    }

    /** Sustituye el carrito guardado del usuario por el indicado (variant_id => cantidad). */
    public function replaceUserCart(int $userId, array $cart): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM cart_items WHERE user_id = :user')->execute(['user' => $userId]);
            $insert = $this->pdo->prepare('INSERT INTO cart_items (user_id, variant_id, quantity) VALUES (:user, :variant, :qty)');
            foreach ($cart as $variantId => $quantity) {
                if ((int) $quantity > 0) {
                    $insert->execute(['user' => $userId, 'variant' => (int) $variantId, 'qty' => (int) $quantity]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
