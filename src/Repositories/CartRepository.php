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

    public function discountCode(string $code): ?array
    {
        $statement = $this->pdo->prepare('SELECT code, type, value FROM discount_codes WHERE code = :code AND active = 1');
        $statement->execute(['code' => $code]);
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
}
