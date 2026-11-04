<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Helpers de presentación compartidos por las plantillas. */
final class Format
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.') . ' €';
    }

    /** Las fechas se guardan en UTC (ISO 8601); se muestran en hora de Madrid. */
    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Madrid'))->format('d/m/Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }
}
