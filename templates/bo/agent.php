<?php
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$boSection = 'agente'; include __DIR__ . '/_nav.php';
$flashOk = $_SESSION['_flash_success'] ?? null; $flashErr = $_SESSION['_flash_error'] ?? null;
unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);
$states = ['pending_review', 'approved', 'rejected', 'executed', 'executed_simulated', 'execution_failed'];
?>
<section class="page-heading"><p class="eyebrow">IA agéntica supervisada</p><h1>Propuestas del agente</h1>
    <p class="muted">El agente solo <strong>propone</strong>. Ninguna acción sobre el cliente se ejecuta hasta que una persona la aprueba aquí; cada decisión queda registrada con autor y fecha.</p></section>
<?php if ($flashOk): ?><p class="notice success" role="status"><?= $esc($flashOk) ?></p><?php endif; ?>
<?php if ($flashErr): ?><p class="notice error" role="alert"><?= $esc($flashErr) ?></p><?php endif; ?>
<nav class="status-chips"><a class="chip<?= $status === '' ? ' active' : '' ?>" href="/bo/agente">Todas <strong><?= (int) array_sum($counts) ?></strong></a>
<?php foreach ($states as $s): ?><a class="chip<?= $status === $s ? ' active' : '' ?>" href="/bo/agente?status=<?= $esc($s) ?>"><?= $esc($s) ?> <strong><?= (int) ($counts[$s] ?? 0) ?></strong></a><?php endforeach; ?></nav>
<?php if ($rows === []): ?><section class="empty-state"><h2>Sin propuestas</h2><p>El agente aún no ha propuesto nada con este filtro.</p></section><?php endif; ?>
<?php foreach ($rows as $r): ?>
<?php $payload = json_decode((string) $r['action_payload'], true) ?: []; $risks = json_decode((string) $r['risks'], true) ?: []; $consulted = json_decode((string) $r['consulted_data'], true) ?: []; ?>
<section class="summary-card" style="margin-bottom:1rem;position:static">
    <h2>#<?= (int) $r['id'] ?> · <?= $esc($r['case_type']) ?> · <a href="/bo/pedidos/<?= $esc($r['order_code']) ?>"><?= $esc($r['order_code']) ?></a> <span class="badge"><?= $esc($r['status']) ?></span></h2>
    <p><strong>Acción propuesta:</strong> <?= $esc($r['action_type']) ?>
        <?php if (isset($payload['message'])): ?>— «<?= $esc($payload['message']) ?>»<?php endif; ?>
        <?php if (isset($payload['new_status'])): ?>→ estado <?= $esc($payload['new_status']) ?><?php endif; ?>
        <?php if (isset($payload['task'])): ?>— <?= $esc($payload['task']) ?><?php endif; ?></p>
    <p><strong>Justificación:</strong> <?= $esc($r['justification']) ?></p>
    <p><strong>Confianza:</strong> <?= $esc(number_format((float) $r['confidence'], 2, ',', '')) ?> · <strong>Prioridad:</strong> <?= $esc($r['priority']) ?> · <strong>Cliente con Telegram:</strong> <?= !empty($r['telegram_chat_id']) ? 'sí' : 'no (se ejecutará en modo simulado)' ?></p>
    <p><strong>Datos consultados:</strong> <?= $esc(implode(' · ', array_map('strval', $consulted))) ?></p>
    <?php if ($risks !== []): ?><p><strong>Riesgos / advertencias:</strong> <?= $esc(implode(' · ', array_map('strval', $risks))) ?></p><?php endif; ?>
    <p class="muted">Modelo: <?= $esc($r['model'] ?? '—') ?> · Creada <?= $esc(str_replace(['T', 'Z'], [' ', ''], $r['created_at'])) ?>
        <?php if ($r['decided_at']): ?> · Decisión de <?= $esc($r['decided_by']) ?> el <?= $esc(str_replace(['T', 'Z'], [' ', ''], $r['decided_at'])) ?><?= $r['decision_note'] ? ' («' . $esc($r['decision_note']) . '»)' : '' ?><?php endif; ?>
        <?php if ($r['executed_at']): ?> · Ejecución: <?= $esc($r['execution_note'] ?? '') ?><?php endif; ?></p>
    <?php if ($r['status'] === 'pending_review'): ?>
    <form method="post" action="/bo/agente/<?= (int) $r['id'] ?>/decidir" class="admin-search">
        <input type="hidden" name="_csrf" value="<?= $esc($csrfToken) ?>">
        <label for="note<?= (int) $r['id'] ?>">Nota</label><input id="note<?= (int) $r['id'] ?>" name="note" maxlength="300" placeholder="Opcional">
        <button class="button" type="submit" name="decision" value="approve">Aprobar</button>
        <button class="button secondary" type="submit" name="decision" value="reject">Rechazar</button>
    </form><?php endif; ?>
</section>
<?php endforeach; ?>
