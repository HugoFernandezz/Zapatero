<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/** Valida los datos de entrega antes de que la capa de pedidos de M4 los consolide. */
final class CheckoutService
{
    public function __construct(private readonly CartService $cart) {}

    public function start(array &$session, ?string $sessionId = null): array
    {
        $quote = $this->cart->summary($session, (string) ($session['discount_code'] ?? ''));
        if ($quote['items'] === []) {
            throw new DomainException('Añade al menos un producto antes de iniciar el pago.');
        }
        return $quote;
    }

    /** @return array{data: array, errors: array<string,string>} */
    public function validate(array $input): array
    {
        $data = [
            'full_name' => trim((string) ($input['full_name'] ?? '')),
            'email' => trim((string) ($input['email'] ?? '')),
            'address' => trim((string) ($input['address'] ?? '')),
            'city' => trim((string) ($input['city'] ?? '')),
            'postal_code' => strtoupper(trim((string) ($input['postal_code'] ?? ''))),
            'province' => trim((string) ($input['province'] ?? '')),
        ];
        // Normaliza espacios repetidos antes de validar, sin alterar letras ni acentos.
        foreach (['full_name', 'city', 'province'] as $field) {
            $data[$field] = preg_replace('/\s+/u', ' ', $data[$field]) ?? $data[$field];
        }
        $errors = [];
        foreach (['full_name' => 'El nombre', 'email' => 'El email', 'address' => 'La dirección',
                  'city' => 'La localidad', 'postal_code' => 'El código postal', 'province' => 'La provincia'] as $field => $label) {
            if ($data[$field] === '') $errors[$field] = "$label es obligatorio.";
            elseif (mb_strlen($data[$field]) > 160) $errors[$field] = "$label es demasiado largo.";
        }
        // Acepta letras Unicode, espacios y separadores habituales de nombres propios.
        foreach (['full_name' => 'El nombre', 'city' => 'La localidad', 'province' => 'La provincia'] as $field => $label) {
            if ($data[$field] !== '' && !preg_match("/^[\\p{L}\\p{M}]+(?:[ '\\x{2019}-][\\p{L}\\p{M}]+)*$/u", $data[$field])) {
                $errors[$field] = "$label solo puede contener letras, espacios, guiones y apóstrofos.";
            }
        }
        if ($data['email'] !== '' && (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['email']) > 254)) {
            $errors['email'] = 'Introduce un email válido.';
        }
        if ($data['postal_code'] !== '' && !preg_match('/^\d{5}$/', $data['postal_code'])) {
            $errors['postal_code'] = 'Introduce un código postal español de 5 cifras.';
        }
        return ['data' => $data, 'errors' => $errors];
    }

    /** Formato compatible con orders.shipping_address (TEXT, no se guarda información de pago). */
    public function addressJson(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
