<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Format;
use Slim\Views\PhpRenderer;

/** Genera la factura (HTML con estilos en línea, apta para correo y para la página imprimible) y su versión en texto. */
final class InvoiceService
{
    public const SELLER = [
        'name' => 'Zapatero S.L. (empresa ficticia)',
        'tax_id' => 'B00000000 (ficticio)',
        'address' => 'Calle del Prototipo 1, 28000 Madrid',
    ];

    public function __construct(private readonly PhpRenderer $view) {}

    public static function number(string $orderCode): string
    {
        return 'FAC-' . $orderCode;
    }

    /** @param array $detail Resultado de OrderService::detail() */
    public function fragment(array $detail): string
    {
        return $this->view->fetch('invoice.php', [
            'detail' => $detail,
            'invoiceNumber' => self::number((string) $detail['order']['code']),
            'seller' => self::SELLER,
        ]);
    }

    public function emailHtml(array $detail): string
    {
        $name = Format::e($detail['customer']['full_name'] ?? '');
        $code = Format::e($detail['order']['code']);

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Factura ' . $code . '</title></head>'
            . '<body style="margin:0;padding:24px;background:#f4efe6;font-family:Arial,Helvetica,sans-serif;color:#171717;">'
            . '<p style="max-width:640px;margin:0 auto 16px;">Hola ' . $name . ',<br>gracias por tu compra. '
            . 'Este es el resumen y la factura de tu pedido <strong>' . $code . '</strong>.</p>'
            . $this->fragment($detail)
            . '<p style="max-width:640px;margin:16px auto 0;font-size:12px;color:#5f6368;">'
            . 'Zapatero es un prototipo académico: el pago es simulado y este documento no tiene validez fiscal.</p>'
            . '</body></html>';
    }

    public function emailText(array $detail): string
    {
        $o = $detail['order'];
        $lines = [
            'Factura ' . self::number((string) $o['code']),
            'Pedido: ' . $o['code'],
            'Cliente: ' . ($detail['customer']['full_name'] ?? ''),
            '',
        ];
        foreach ($detail['items'] as $item) {
            $lines[] = sprintf(
                '- %s (EU %d) x%d: %s',
                $item['name'],
                (int) $item['size_eu'],
                (int) $item['quantity'],
                Format::money((int) $item['unit_price_cents'] * (int) $item['quantity'])
            );
        }
        $lines[] = '';
        $lines[] = 'Subtotal: ' . Format::money((int) $o['subtotal_cents']);
        if ((int) $o['discount_cents'] > 0) {
            $lines[] = 'Descuento: -' . Format::money((int) $o['discount_cents']);
        }
        $lines[] = 'Envío: ' . ((int) $o['shipping_cents'] === 0 ? 'Gratis' : Format::money((int) $o['shipping_cents']));
        $lines[] = 'Base imponible: ' . Format::money((int) $o['total_cents'] - (int) $o['tax_cents']);
        $lines[] = 'IVA incluido (21 %): ' . Format::money((int) $o['tax_cents']);
        $lines[] = 'TOTAL: ' . Format::money((int) $o['total_cents']);
        $lines[] = '';
        $lines[] = 'Prototipo académico: pago simulado, sin validez fiscal.';

        return implode("\r\n", $lines);
    }
}
