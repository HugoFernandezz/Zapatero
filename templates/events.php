<?php

declare(strict_types=1);

$events = $events ?? [];

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle ?? 'Eventos - Zapatero') ?></title>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 30px;
        }

        h1 {
            margin-bottom: 20px;
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

        pre {
            margin: 0;
            white-space: pre-wrap;
        }
    </style>
</head>

<body>

<h1>Eventos de Zapatero</h1>

<table>
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

</body>
</html>