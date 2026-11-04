<section class="page-heading">
    <p class="eyebrow">Pedido <?= htmlspecialchars((string) $detail['order']['code'], ENT_QUOTES, 'UTF-8') ?></p>
    <h1>Factura</h1>
</section>
<p class="invoice-actions">
    <a class="button secondary" href="/pedido/<?= rawurlencode((string) $detail['order']['code']) ?>">← Volver al pedido</a>
    <button class="button primary" type="button" onclick="window.print()">Imprimir</button>
</p>
<div class="invoice-sheet"><?= $invoiceHtml ?></div>
