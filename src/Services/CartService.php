<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CartRepository;
use DomainException;

/** Reglas del carrito. La sesión solo conserva variant_id => cantidad; el precio se consulta de nuevo. */
final class CartService
{
    private const VAT_NUMERATOR = 21;
    private const VAT_DENOMINATOR = 121;
    private const SHIPPING_CENTS = 495;
    private const FREE_SHIPPING_FROM_CENTS = 6000;

    public function __construct(
        private readonly CartRepository $repository,
        private readonly mixed $recordEvent = null
    ) {}

    public function add(array &$session, int $variantId, int $quantity = 1, ?string $sessionId = null): void
    {
        if ($quantity < 1 || $quantity > 99) {
            throw new DomainException('La cantidad debe estar entre 1 y 99.');
        }
        $variant = $this->repository->variant($variantId);
        if ($variant === null) {
            throw new DomainException('La talla seleccionada ya no está disponible.');
        }
        $newQuantity = (int) ($session['cart'][$variantId] ?? 0) + $quantity;
        if ($newQuantity > (int) $variant['stock']) {
            throw new DomainException('No hay suficientes unidades disponibles para esa talla.');
        }
        $session['cart'][$variantId] = $newQuantity;
        if (is_callable($this->recordEvent)) {
            ($this->recordEvent)('cart.item_added', [
                'variant_id' => $variantId,
                'size_eu' => (int) $variant['size_eu'],
                'quantity' => $quantity,
            ], $sessionId);
        }
    }

    public function update(array &$session, int $variantId, int $quantity): void
    {
        if (!isset($session['cart'][$variantId])) {
            throw new DomainException('El producto no está en el carrito.');
        }
        if ($quantity === 0) {
            unset($session['cart'][$variantId]);
            return;
        }
        if ($quantity < 1 || $quantity > 99) {
            throw new DomainException('La cantidad debe estar entre 1 y 99, o ser cero para eliminar.');
        }
        $variant = $this->repository->variant($variantId);
        if ($variant === null || $quantity > (int) $variant['stock']) {
            throw new DomainException('La cantidad supera el stock actual de esa talla.');
        }
        $session['cart'][$variantId] = $quantity;
    }

    public function remove(array &$session, int $variantId): void
    {
        unset($session['cart'][$variantId]);
    }

    public function summary(array &$session, ?string $discountCode = null): array
    {
        $quantities = $session['cart'] ?? [];
        $items = $this->repository->variants($quantities);
        // Limpia de sesión variantes borradas y no acepta cantidades inválidas almacenadas.
        $valid = [];
        $availableItems = [];
        foreach ($items as $item) {
            $id = (int) $item['variant_id'];
            $qty = (int) ($quantities[$id] ?? 0);
            if ($qty > 0 && $qty <= (int) $item['stock']) {
                $valid[$id] = $qty;
                $item['quantity'] = $qty;
                $item['line_total_cents'] = (int) $item['price_cents'] * $qty;
                $availableItems[] = $item;
            }
        }
        $session['cart'] = $valid;
        $items = $availableItems;

        $subtotal = array_sum(array_column($items, 'line_total_cents'));
        [$discount, $appliedCode] = $this->discount($discountCode, $subtotal);
        $shipping = $subtotal === 0 || $subtotal >= self::FREE_SHIPPING_FROM_CENTS ? 0 : self::SHIPPING_CENTS;
        $taxableTotal = max(0, $subtotal - $discount) + $shipping;
        // Redondeo comercial a céntimos; IVA desglosado de importes que ya lo incluyen.
        $tax = (int) round($taxableTotal * self::VAT_NUMERATOR / self::VAT_DENOMINATOR, 0, PHP_ROUND_HALF_UP);

        return [
            'items' => $items,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'discount_code' => $appliedCode,
            'shipping_cents' => $shipping,
            'tax_cents' => $tax,
            'total_cents' => $taxableTotal,
        ];
    }

    public function isValidDiscount(string $code): bool
    {
        return $this->repository->discountCode($code) !== null;
    }

    private function discount(?string $code, int $subtotal): array
    {
        $code = strtoupper(trim((string) $code));
        $row = $code === '' || $subtotal === 0 ? null : $this->repository->discountCode($code);
        if ($row === null) {
            return [0, null];
        }
        $amount = $row['type'] === 'percent'
            ? (int) round($subtotal * (int) $row['value'] / 100, 0, PHP_ROUND_HALF_UP)
            : min((int) $row['value'], $subtotal);
        return [$amount, $row['code']];
    }
}
