<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Estados del pedido y máquina de transiciones (ver PLANNING.md §6).
 * Los valores coinciden con el CHECK de orders.status en schema.sql.
 */
final class OrderStatus
{
    public const CREATED = 'created';
    public const PAID = 'paid_simulated';
    public const PENDING = 'pending_preparation';
    public const SHIPPED = 'shipped';
    public const INCIDENT = 'incident';
    public const CANCELLED = 'cancelled';

    private const LABELS = [
        self::CREATED => 'Creado',
        self::PAID => 'Pagado (simulado)',
        self::PENDING => 'Pendiente de preparación',
        self::SHIPPED => 'Enviado',
        self::INCIDENT => 'Con incidencia',
        self::CANCELLED => 'Cancelado',
    ];

    private const TRANSITIONS = [
        self::CREATED => [self::PAID, self::CANCELLED],
        self::PAID => [self::PENDING, self::INCIDENT, self::CANCELLED],
        self::PENDING => [self::SHIPPED, self::INCIDENT, self::CANCELLED],
        self::SHIPPED => [self::INCIDENT],
        self::INCIDENT => [self::PENDING, self::CANCELLED],
        self::CANCELLED => [],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public static function isValid(string $status): bool
    {
        return isset(self::LABELS[$status]);
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * Cambios que puede hacer una persona desde el back-office.
     * created -> paid_simulated solo lo hace el flujo de pago, nunca a mano.
     *
     * @return list<string>
     */
    public static function adminOptions(string $from): array
    {
        return array_values(array_filter(
            self::TRANSITIONS[$from] ?? [],
            static fn (string $to): bool => !($from === self::CREATED && $to === self::PAID)
        ));
    }
}
