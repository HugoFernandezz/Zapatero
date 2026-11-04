<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Format;
use App\Support\OrderStatus;

/**
 * Correos al cliente cuando cambia el estado de su pedido (preparación, envío, incidencia, cancelación).
 * "Pagado" no genera correo propio: ya lo cubre el email de factura que se envía al pagar.
 * Las notas internas del administrador nunca se incluyen en el mensaje.
 */
final class OrderNotifier
{
    private const COLORS = [
        OrderStatus::PENDING => '#b26a00',
        OrderStatus::SHIPPED => '#006d77',
        OrderStatus::INCIDENT => '#b3261e',
        OrderStatus::CANCELLED => '#5f6368',
    ];

    /** ¿Este estado envía correo al cliente? */
    public function handles(string $status): bool
    {
        return isset(self::COLORS[$status]);
    }

    public function subject(string $status, string $code, string $from = ''): string
    {
        return match (true) {
            $status === OrderStatus::PENDING && $from === OrderStatus::INCIDENT => "Hemos resuelto la incidencia de tu pedido $code - Zapatero",
            $status === OrderStatus::PENDING => "Estamos preparando tu pedido $code - Zapatero",
            $status === OrderStatus::SHIPPED => "Tu pedido $code ha sido enviado - Zapatero",
            $status === OrderStatus::INCIDENT => "Incidencia en tu pedido $code - Zapatero",
            $status === OrderStatus::CANCELLED => "Tu pedido $code ha sido cancelado - Zapatero",
            default => "Novedades de tu pedido $code - Zapatero",
        };
    }

    /** @return array{title: string, body: string} */
    private function message(string $status, string $from): array
    {
        return match (true) {
            $status === OrderStatus::PENDING && $from === OrderStatus::INCIDENT => [
                'title' => 'Incidencia resuelta',
                'body' => 'Hemos resuelto la incidencia de tu pedido y retomamos su preparación. Te avisaremos cuando salga hacia tu dirección.',
            ],
            $status === OrderStatus::PENDING => [
                'title' => 'Estamos preparando tu pedido',
                'body' => 'Tu pago está confirmado y nuestro equipo ya está preparando tu pedido. Te avisaremos en cuanto salga hacia tu dirección.',
            ],
            $status === OrderStatus::SHIPPED => [
                'title' => '¡Tu pedido ha sido enviado!',
                'body' => 'Tu pedido ya ha salido de nuestro almacén y va de camino a la dirección de entrega.',
            ],
            $status === OrderStatus::INCIDENT => [
                'title' => 'Hay una incidencia con tu pedido',
                'body' => 'Hemos detectado una incidencia con tu pedido y estamos trabajando para resolverla. Si tienes dudas, responde a este correo indicando tu número de pedido.',
            ],
            $status === OrderStatus::CANCELLED => [
                'title' => 'Tu pedido ha sido cancelado',
                'body' => 'Hemos cancelado tu pedido. Si ya se había realizado el cobro, el importe se te devolverá por el mismo medio de pago.',
            ],
            default => ['title' => 'Novedades de tu pedido', 'body' => 'El estado de tu pedido ha cambiado.'],
        };
    }

    /** @param array $detail Resultado de OrderService::detail() */
    public function html(array $detail, string $status, string $from = ''): string
    {
        $msg = $this->message($status, $from);
        $color = self::COLORS[$status] ?? '#171717';
        $o = $detail['order'];
        $c = $detail['customer'];
        $name = Format::e($c['full_name'] ?? '');
        $td = 'padding:8px 6px;border-bottom:1px solid #ded8ce;vertical-align:top;';

        $rows = '';
        foreach ($detail['items'] as $item) {
            $rows .= '<tr><td style="' . $td . '">' . Format::e($item['name'])
                . '<br><span style="font-size:12px;color:#5f6368;">Talla EU ' . (int) $item['size_eu'] . '</span></td>'
                . '<td style="' . $td . 'text-align:right;">x' . (int) $item['quantity'] . '</td></tr>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . Format::e($msg['title']) . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f4efe6;font-family:Arial,Helvetica,sans-serif;color:#171717;">'
            . '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #ded8ce;padding:24px;font-size:14px;line-height:1.5;">'
            . '<div style="font-size:22px;font-weight:bold;color:#006d77;">Zapatero</div>'
            . '<p style="margin:20px 0 6px;">Hola ' . $name . ',</p>'
            . '<h1 style="margin:0 0 12px;font-size:20px;color:' . $color . ';">' . Format::e($msg['title']) . '</h1>'
            . '<p style="margin:0 0 16px;">' . Format::e($msg['body']) . '</p>'
            . '<p style="margin:0 0 16px;"><span style="display:inline-block;padding:4px 10px;border-radius:12px;background:' . $color . ';color:#ffffff;font-size:12px;font-weight:bold;">'
            . Format::e(OrderStatus::label($status)) . '</span> &nbsp; Pedido <strong>' . Format::e($o['code']) . '</strong></p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">' . $rows . '</table>'
            . '<p style="margin:16px 0 4px;font-size:12px;text-transform:uppercase;color:#5f6368;">Dirección de entrega</p>'
            . '<p style="margin:0 0 16px;">' . Format::e($c['full_name'] ?? '') . '<br>' . Format::e($c['address'] ?? '') . '<br>'
            . Format::e($c['postal_code'] ?? '') . ' ' . Format::e($c['city'] ?? '') . ' (' . Format::e($c['province'] ?? '') . ')</p>'
            . '<p style="margin:0;font-size:12px;color:#5f6368;">Total del pedido: ' . Format::money((int) $o['total_cents'])
            . '. Zapatero es un prototipo académico: el pago es simulado.</p>'
            . '</div></body></html>';
    }

    public function text(array $detail, string $status, string $from = ''): string
    {
        $msg = $this->message($status, $from);
        $o = $detail['order'];
        $c = $detail['customer'];
        $lines = [
            'Hola ' . ($c['full_name'] ?? '') . ',',
            '',
            $msg['title'],
            $msg['body'],
            '',
            'Pedido: ' . $o['code'] . ' · Estado: ' . OrderStatus::label($status),
            '',
        ];
        foreach ($detail['items'] as $item) {
            $lines[] = sprintf('- %s (EU %d) x%d', $item['name'], (int) $item['size_eu'], (int) $item['quantity']);
        }
        $lines[] = '';
        $lines[] = 'Entrega en: ' . ($c['address'] ?? '') . ', ' . ($c['postal_code'] ?? '') . ' ' . ($c['city'] ?? '');
        $lines[] = 'Total: ' . Format::money((int) $o['total_cents']);
        $lines[] = '';
        $lines[] = 'Prototipo académico: pago simulado.';

        return implode("\r\n", $lines);
    }
}
