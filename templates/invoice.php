<?php
// Fragmento de factura con estilos en línea: se reutiliza en el correo y en la página imprimible.
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$m = static fn (int $cents): string => \App\Support\Format::money($cents);
$o = $detail['order'];
$c = $detail['customer'];
$p = $detail['payment'];
$total = (int) $o['total_cents'];
$tax = (int) $o['tax_cents'];
$th = 'padding:8px 6px;border-bottom:2px solid #171717;text-align:left;font-size:12px;text-transform:uppercase;';
$td = 'padding:8px 6px;border-bottom:1px solid #ded8ce;vertical-align:top;';
$tr = 'padding:4px 6px;text-align:right;';
?>
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #ded8ce;padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.45;color:#171717;">
    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td style="vertical-align:top;">
                <div style="font-size:22px;font-weight:bold;color:#006d77;">Zapatero</div>
                <div style="font-size:12px;color:#5f6368;"><?= $e($seller['name']) ?><br>NIF: <?= $e($seller['tax_id']) ?><br><?= $e($seller['address']) ?></div>
            </td>
            <td style="vertical-align:top;text-align:right;">
                <div style="font-size:18px;font-weight:bold;">FACTURA</div>
                <div><?= $e($invoiceNumber) ?></div>
                <div style="font-size:12px;color:#5f6368;">Fecha: <?= $e(\App\Support\Format::date($o['created_at'])) ?></div>
                <div style="font-size:12px;color:#5f6368;">Pedido: <?= $e($o['code']) ?></div>
            </td>
        </tr>
    </table>

    <p style="margin:20px 0 4px;font-size:12px;text-transform:uppercase;color:#5f6368;">Facturar a</p>
    <p style="margin:0 0 20px;">
        <?= $e($c['full_name'] ?? '') ?><br>
        <?= $e($c['address'] ?? '') ?><br>
        <?= $e($c['postal_code'] ?? '') ?> <?= $e($c['city'] ?? '') ?> (<?= $e($c['province'] ?? '') ?>)<br>
        <?= $e($c['email'] ?? '') ?>
    </p>

    <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <th style="<?= $th ?>">Producto</th>
            <th style="<?= $th ?>text-align:right;">Cant.</th>
            <th style="<?= $th ?>text-align:right;">Precio</th>
            <th style="<?= $th ?>text-align:right;">Importe</th>
        </tr>
        <?php foreach ($detail['items'] as $item): ?>
            <tr>
                <td style="<?= $td ?>"><?= $e($item['name']) ?><br><span style="font-size:12px;color:#5f6368;">Talla EU <?= (int) $item['size_eu'] ?> · <?= $e($item['sku']) ?></span></td>
                <td style="<?= $td ?>text-align:right;"><?= (int) $item['quantity'] ?></td>
                <td style="<?= $td ?>text-align:right;"><?= $m((int) $item['unit_price_cents']) ?></td>
                <td style="<?= $td ?>text-align:right;"><?= $m((int) $item['unit_price_cents'] * (int) $item['quantity']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0 0 auto;min-width:260px;">
        <tr><td style="<?= $tr ?>">Subtotal</td><td style="<?= $tr ?>"><?= $m((int) $o['subtotal_cents']) ?></td></tr>
        <?php if ((int) $o['discount_cents'] > 0): ?>
            <tr><td style="<?= $tr ?>">Descuento<?= !empty($o['discount_code']) ? ' ' . $e($o['discount_code']) : '' ?></td><td style="<?= $tr ?>">−<?= $m((int) $o['discount_cents']) ?></td></tr>
        <?php endif; ?>
        <tr><td style="<?= $tr ?>">Envío</td><td style="<?= $tr ?>"><?= (int) $o['shipping_cents'] === 0 ? 'Gratis' : $m((int) $o['shipping_cents']) ?></td></tr>
        <tr><td style="<?= $tr ?>">Base imponible</td><td style="<?= $tr ?>"><?= $m($total - $tax) ?></td></tr>
        <tr><td style="<?= $tr ?>">IVA incluido (21 %)</td><td style="<?= $tr ?>"><?= $m($tax) ?></td></tr>
        <tr><td style="<?= $tr ?>border-top:2px solid #171717;font-weight:bold;font-size:16px;">TOTAL</td><td style="<?= $tr ?>border-top:2px solid #171717;font-weight:bold;font-size:16px;"><?= $m($total) ?></td></tr>
    </table>

    <p style="margin:20px 0 0;font-size:12px;color:#5f6368;">
        Forma de pago: tarjeta de prueba (simulada)<?= !empty($p['card_last4']) ? ' terminada en ' . $e($p['card_last4']) : '' ?>
        · Ref. <?= $e($p['reference'] ?? '') ?><br>
        Documento ficticio de un prototipo académico, sin validez fiscal.
    </p>
</div>
