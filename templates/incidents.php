<?php

declare(strict_types=1);

$tickets = $tickets ?? [];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Incidencias') ?></title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
        }

        th {
            background: #f2f2f2;
        }

        .incident {
            font-weight: bold;
        }
    </style>
</head>

<body>

<h1>Incidencias y soporte</h1>

<table>

    <thead>
        <tr>
            <th>ID</th>
            <th>Pedido</th>
            <th>Asunto</th>
            <th>Mensaje</th>
            <th>Estado ticket</th>
            <th>Estado pedido</th>
            <th>Fecha</th>
            <th>Acción</th>
        </tr>
    </thead>

    <tbody>

    <?php foreach ($tickets as $ticket): ?>

        <tr>

            <td>
                <?= htmlspecialchars(
                    (string) $ticket['id']
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    (string) ($ticket['order_code'] ?? '')
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    (string) $ticket['subject']
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    (string) $ticket['message']
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    (string) $ticket['status']
                ) ?>
            </td>

            <td class="incident">
                <?= htmlspecialchars(
                    (string) ($ticket['order_status'] ?? '')
                ) ?>
            </td>

            <td>
                <?= htmlspecialchars(
                    (string) $ticket['created_at']
                ) ?>
            </td>

            <td>

                <?php if (
                    $ticket['order_id'] !== null
                    && $ticket['order_status'] !== 'incident'
                    && $ticket['status'] !== 'closed'
                ): ?>

                    <form
                        method="post"
                        action="/admin/incidencias/<?= (int) $ticket['id'] ?>/crear"
                    >
                        <button type="submit">
                            Crear incidencia
                        </button>
                    </form>

                <?php elseif (
                    $ticket['order_status'] === 'incident'
                ): ?>

                    <form
                        method="post"
                        action="/admin/incidencias/<?= (int) $ticket['id'] ?>/resolver"
                    >
                        <button type="submit">
                            Resolver
                        </button>
                    </form>

                <?php else: ?>

                    -

                <?php endif; ?>

            </td>

        </tr>

    <?php endforeach; ?>

    </tbody>

</table>

<p>
    <a href="/admin/events">Ver eventos</a>
</p>

</body>
</html>