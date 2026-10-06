<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Pasarela de pago SIMULADA. No contacta con ningún banco: cualquier tarjeta es válida salvo 0000 0000 0000 0000,
 * que la pasarela deniega.
 * El número completo y el CVC nunca se guardan: solo se conservan los 4 últimos dígitos.
 */
final class PaymentService
{
    public const REJECTED_CARD = '0000000000000000';

    /** @return array{errors: array<string,string>, holder: string, expiry: string, approved: bool, last4: string} */
    public function evaluate(array $input, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $holder = trim((string) ($input['card_holder'] ?? ''));
        $number = preg_replace('/[\s-]+/', '', (string) ($input['card_number'] ?? '')) ?? '';
        $expiry = trim((string) ($input['card_expiry'] ?? ''));
        $cvc = trim((string) ($input['card_cvc'] ?? ''));
        $errors = [];

        if ($holder === '') {
            $errors['card_holder'] = 'El titular es obligatorio.';
        } elseif (mb_strlen($holder) > 80) {
            $errors['card_holder'] = 'El titular es demasiado largo.';
        }

        if ($number === '') {
            $errors['card_number'] = 'El número de tarjeta es obligatorio.';
        } elseif (!preg_match('/^\d{13,19}$/', $number)) {
            $errors['card_number'] = 'Introduce un número de tarjeta de 13 a 19 cifras.';
        }

        if (!preg_match('/^(0[1-9]|1[0-2])\s*\/\s*(\d{2})$/', $expiry, $m)) {
            $errors['card_expiry'] = 'Introduce la caducidad con formato MM/AA.';
        } else {
            $year = 2000 + (int) $m[2];
            $month = (int) $m[1];
            $currentYear = (int) $now->format('Y');
            if ($year < $currentYear || ($year === $currentYear && $month < (int) $now->format('n'))) {
                $errors['card_expiry'] = 'La tarjeta está caducada.';
            }
        }

        if (!preg_match('/^\d{3}$/', $cvc)) {
            $errors['card_cvc'] = 'El CVC son 3 cifras.';
        }

        return [
            'errors' => $errors,
            'holder' => $holder,
            'expiry' => $expiry,
            'approved' => !isset($errors['card_number']) && $number !== self::REJECTED_CARD,
            'last4' => isset($errors['card_number']) ? '' : substr($number, -4),
        ];
    }
}
