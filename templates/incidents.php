<?php

declare(strict_types=1);

$tickets = $tickets ?? [];

?>

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

            <td>
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