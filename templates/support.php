<?php

declare(strict_types=1);

$old = $old ?? [];

?>

<h1>Soporte</h1>

<?php if (!empty($error)): ?>
    <div class="error">
        <?= htmlspecialchars((string) $error) ?>
    </div>
<?php endif; ?>

<form method="post" action="/soporte">

    <label for="order_code">
        Código del pedido
    </label>

    <input
        type="text"
        id="order_code"
        name="order_code"
        value="<?= htmlspecialchars(
            (string) ($old['order_code'] ?? '')
        ) ?>"
        placeholder="ZAP-20260929-0001"
    >

    <label for="subject">
        Asunto
    </label>

    <input
        type="text"
        id="subject"
        name="subject"
        required
        value="<?= htmlspecialchars(
            (string) ($old['subject'] ?? '')
        ) ?>"
    >

    <label for="message">
        Motivo / mensaje
    </label>

    <textarea
        id="message"
        name="message"
        required
    ><?= htmlspecialchars(
        (string) ($old['message'] ?? '')
    ) ?></textarea>

    <button type="submit">
        Enviar solicitud
    </button>

</form>

<p>
    <a href="/">Volver a inicio</a>
</p>