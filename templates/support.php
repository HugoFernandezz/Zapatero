<?php

declare(strict_types=1);

$old = $old ?? [];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Soporte') ?></title>

    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 700px;
            margin: 40px auto;
            padding: 0 20px;
        }

        label {
            display: block;
            margin-top: 15px;
            margin-bottom: 5px;
        }

        input,
        textarea {
            width: 100%;
            padding: 10px;
            box-sizing: border-box;
        }

        textarea {
            min-height: 150px;
        }

        button {
            margin-top: 20px;
            padding: 10px 20px;
        }

        .error {
            background: #f8d7da;
            padding: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>

<body>

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

</body>
</html>