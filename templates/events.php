<?php

declare(strict_types=1);

$events = $events ?? [];

?>

<h1>Eventos de Zapatero</h1>

<table class="admin-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>Tipo</th>
            <th>Fecha</th>
            <th>Sesión</th>
            <th>Usuario</th>
            <th>Payload</th>
        </tr>
    </thead>

    <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= htmlspecialchars((string) $event['id']) ?></td>
                <td><?= htmlspecialchars((string) $event['type']) ?></td>
                <td><?= htmlspecialchars((string) $event['occurred_at']) ?></td>
                <td><?= htmlspecialchars((string) ($event['session_id'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($event['user_id'] ?? '')) ?></td>
                <td>
                    <pre><?= htmlspecialchars((string) $event['payload']) ?></pre>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>