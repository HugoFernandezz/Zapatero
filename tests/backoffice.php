<?php

// Test del back-office de integración:  composer test   (o: php tests/backoffice.php)
// Cubre idempotencia, validación, sincronización pendiente, orden temporal y guardarraíles del agente.

declare(strict_types=1);

use App\BackOffice\BoDatabase;
use App\BackOffice\BoException;
use App\BackOffice\BoRepository;
use App\BackOffice\BoService;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/zapatero-bo-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$pdo = BoDatabase::connect($tmp . '/bo.sqlite');
$svc = new BoService(new BoRepository($pdo));

$failures = 0;
$check = static function (bool $ok, string $msg) use (&$failures): void {
    echo ($ok ? '  OK    ' : '  FALLO ') . $msg . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};
$scalar = static fn (string $sql) => $pdo->query($sql)->fetchColumn();
$eid = static fn (int $n): string => sprintf('evt_%032x', $n);
$fails = static function (callable $fn, string $code): bool {
    try {
        $fn();
    } catch (BoException $e) {
        return $e->errorCode === $code;
    }

    return false;
};
$order = static fn (string $code = 'ZAP-20261030-0001', string $status = 'paid_simulated', string $at = '2026-10-30T10:00:00Z'): array => [
    'order_code' => $code, 'status' => $status, 'created_at' => '2026-10-30T10:00:00Z', 'status_updated_at' => $at, 'currency' => 'EUR',
    'subtotal_cents' => 12000, 'discount_cents' => 1200, 'shipping_cents' => 0, 'tax_cents' => 1875, 'total_cents' => 10800,
    'customer' => ['full_name' => 'Cliente Prueba', 'email' => 'Cliente@Zapatero.test', 'city' => 'Murcia', 'province' => 'Murcia'],
    'items' => [['sku' => 'ZAP-001-42', 'name' => 'Arcade Runner', 'size_eu' => 42, 'quantity' => 1, 'unit_price_cents' => 12000]],
    'payment' => ['method' => 'card_simulated', 'status' => 'approved', 'reference' => 'SIM-ABCDE12345'],
];

echo "Escenario 1: pedido\n";
$r = $svc->ingestOrder(['event_id' => $eid(1), 'event_type' => 'order.created', 'workflow' => 'wf1', 'order' => $order()]);
$check($r['result'] === 'created', 'crea el pedido');
$check((int) $scalar('SELECT COUNT(*) FROM bo_order_lines') === 1, 'guarda las líneas');
$check($scalar("SELECT email FROM bo_customers") === 'cliente@zapatero.test', 'normaliza el email del cliente a minúsculas');
$r = $svc->ingestOrder(['event_id' => $eid(1), 'event_type' => 'order.created', 'order' => $order()]);
$check($r['result'] === 'duplicate', 'el mismo event_id es idempotente (duplicate)');
$check((int) $scalar('SELECT COUNT(*) FROM bo_orders') === 1, 'no duplica el pedido');
$check((int) $scalar("SELECT COUNT(*) FROM bo_integration_log WHERE status = 'duplicate'") === 1, 'el duplicado queda registrado en el log');

echo "Validación\n";
$bad = $order();
$bad['customer']['email'] = 'no-es-email';
$bad['items'] = [];
$check($fails(fn () => $svc->ingestOrder(['event_id' => $eid(2), 'order' => $bad]), 'validation_failed'), 'rechaza email inválido y pedido sin líneas');
$check((int) $scalar("SELECT COUNT(*) FROM bo_integration_log WHERE status = 'rejected'") === 1, 'el rechazo queda registrado');
$check($fails(fn () => $svc->ingestOrder(['event_id' => 'malo', 'order' => $order('ZAP-20261030-0002')]), 'validation_failed'), 'rechaza event_id con formato incorrecto');
$check((int) $scalar('SELECT COUNT(*) FROM bo_orders') === 1, 'un rechazo no crea entidades');
$strInt = $order('ZAP-20261030-0003');
$strInt['total_cents'] = '100';
$check($fails(fn () => $svc->ingestOrder(['event_id' => $eid(3), 'order' => $strInt]), 'validation_failed'), 'rechaza importes que no son enteros');

echo "Escenario 2: sincronización\n";
$r = $svc->changeOrderStatus('ZAP-20261030-0001', ['event_id' => $eid(10), 'event_type' => 'order.status_changed', 'status' => 'pending_preparation', 'occurred_at' => '2026-10-30T11:00:00Z']);
$check($r['result'] === 'updated' && $scalar("SELECT status FROM bo_orders WHERE order_code='ZAP-20261030-0001'") === 'pending_preparation', 'aplica el cambio de estado');
$r = $svc->changeOrderStatus('ZAP-20261030-0001', ['event_id' => $eid(11), 'event_type' => 'order.status_changed', 'status' => 'paid_simulated', 'occurred_at' => '2026-10-30T10:30:00Z']);
$check($r['result'] === 'stale' && $scalar("SELECT status FROM bo_orders WHERE order_code='ZAP-20261030-0001'") === 'pending_preparation', 'ignora un evento más antiguo (orden temporal)');
$r = $svc->ingestOrder(['event_id' => $eid(12), 'event_type' => 'order.created', 'order' => $order('ZAP-20261030-0001', 'created', '2026-10-30T10:00:00Z')]);
$check($scalar("SELECT status FROM bo_orders WHERE order_code='ZAP-20261030-0001'") === 'pending_preparation', 'una foto antigua del pedido no hace retroceder el estado');
$r = $svc->changeOrderStatus('ZAP-20261030-0009', ['event_id' => $eid(13), 'event_type' => 'order.status_changed', 'status' => 'shipped', 'occurred_at' => '2026-10-30T12:00:00Z']);
$check($r['result'] === 'pending_sync' && $svc->repo()->pendingCount() === 1, 'pedido desconocido => pendiente de sincronización');
$svc->ingestOrder(['event_id' => $eid(14), 'event_type' => 'order.created', 'order' => $order('ZAP-20261030-0009', 'paid_simulated', '2026-10-30T10:00:00Z')]);
$check($scalar("SELECT status FROM bo_orders WHERE order_code='ZAP-20261030-0009'") === 'shipped' && $svc->repo()->pendingCount() === 0, 'al llegar el pedido se aplica el cambio pendiente');
$check($fails(fn () => $svc->changeOrderStatus('ZAP-20261030-0001', ['event_id' => $eid(15), 'status' => 'volando']), 'validation_failed'), 'rechaza estados desconocidos');

echo "Escenario 3: tickets\n";
$t = ['ticket_ref' => 'SUP-1', 'kind' => 'support', 'order_code' => 'ZAP-20261030-0001', 'subject' => 'Cambio de talla', 'message' => 'Me queda pequeña, quiero cambiarla.', 'created_at' => '2026-10-30T13:00:00Z'];
$r = $svc->ingestTicket(['event_id' => $eid(20), 'event_type' => 'support.requested', 'ticket' => $t]);
$check($r['result'] === 'created' && (int) $scalar('SELECT COUNT(*) FROM bo_tickets') === 1, 'crea el ticket asociado a pedido y cliente');
$check($svc->ingestTicket(['event_id' => $eid(21), 'event_type' => 'support.requested', 'ticket' => $t])['result'] === 'duplicate', 'ticket repetido => duplicate aunque cambie el event_id');
$r = $svc->ingestTicket(['event_id' => $eid(22), 'event_type' => 'incident.created', 'ticket' => ['ticket_ref' => 'INC-1', 'kind' => 'incident', 'order_code' => 'ZAP-20261030-0077', 'subject' => 'Incidencia', 'message' => 'Sin motivo indicado']]);
$check($r['result'] === 'pending_sync', 'ticket de un pedido desconocido queda pendiente');
$svc->updateTicket('SUP-1', ['status' => 'resolved']);
$check($scalar("SELECT resolved_at FROM bo_tickets WHERE ticket_ref='SUP-1'") !== null, 'resolver un ticket guarda resolved_at');
$svc->updateTicket('SUP-1', ['status' => 'open']);
$check($scalar("SELECT resolved_at FROM bo_tickets WHERE ticket_ref='SUP-1'") === null, 'reabrir lo borra');

echo "Agente de IA\n";
$base = [
    'ticket_ref' => 'SUP-1', 'order_code' => 'ZAP-20261030-0001', 'case_type' => 'size_change',
    'consulted_data' => ['pedido ZAP-20261030-0001', 'historial de incidencias del cliente'],
    'action' => ['type' => 'customer_message', 'message' => 'Hola, hemos recibido tu solicitud de cambio de talla.'],
    'justification' => 'El cliente pide cambio de talla y el pedido está en preparación.', 'confidence' => 0.9, 'priority' => 'normal', 'risks' => [], 'model' => 'test',
];
$p = $svc->proposeAgentAction($base);
$check($p['status'] === 'pending_review', 'la propuesta nace pendiente de revisión humana');
$check($fails(fn () => $svc->reportExecution($p['id'], 'sent', ''), 'not_approved'), 'no se puede ejecutar sin aprobación');
$cancel = $base;
$cancel['action'] = ['type' => 'status_change_proposal', 'new_status' => 'cancelled'];
$check($fails(fn () => $svc->proposeAgentAction($cancel), 'validation_failed'), 'el agente no puede proponer cancelar pedidos');
$unknown = $base;
$unknown['action'] = ['type' => 'refund', 'amount' => 100];
$check($fails(fn () => $svc->proposeAgentAction($unknown), 'validation_failed'), 'acciones fuera de la lista permitida se rechazan');
$noOrder = $base;
$noOrder['order_code'] = 'ZAP-20261030-0555';
$check($fails(fn () => $svc->proposeAgentAction($noOrder), 'validation_failed'), 'rechaza propuestas sobre pedidos inexistentes');
$low = $base;
$low['confidence'] = 0.3;
$pl = $svc->proposeAgentAction($low);
$check($scalar("SELECT priority FROM bo_agent_actions WHERE id = {$pl['id']}") === 'high', 'confianza baja eleva la prioridad de revisión');
$check(str_contains((string) $scalar("SELECT risks FROM bo_agent_actions WHERE id = {$pl['id']}"), 'Confianza baja'), 'y añade una advertencia de riesgo');
$svc->decide($p['id'], 'approve', 'admin@zapatero.test', 'ok');
$check($scalar("SELECT status FROM bo_agent_actions WHERE id = {$p['id']}") === 'approved' && $scalar("SELECT decided_by FROM bo_agent_actions WHERE id = {$p['id']}") === 'admin@zapatero.test', 'la aprobación registra quién decide');
$check($fails(fn () => $svc->decide($p['id'], 'reject', 'otro@zapatero.test', ''), 'invalid_transition'), 'no se puede cambiar una decisión ya tomada');
$check($svc->reportExecution($p['id'], 'simulated', 'sin chat')['status'] === 'executed_simulated', 'tras aprobar se puede ejecutar (modo simulado)');
$svc->decide($pl['id'], 'reject', 'admin@zapatero.test', 'no procede');
$check($fails(fn () => $svc->reportExecution($pl['id'], 'sent', ''), 'not_approved'), 'una propuesta rechazada no se ejecuta nunca');

echo "Telegram y estado\n";
$svc->linkTelegram('ZAP-20261030-0001', '123456789');
$check($scalar('SELECT telegram_chat_id FROM bo_customers LIMIT 1') === '123456789', 'vincula el chat de Telegram al cliente del pedido');
$check($fails(fn () => $svc->linkTelegram('ZAP-20261030-0001', 'abc'), 'validation_failed'), 'valida el chat_id');
echo "Telegram: enlace con token de un solo uso\n";
$tok = $svc->issueTelegramToken('ZAP-20261030-0001');
$check(preg_match('/^[0-9a-f]{32}$/', $tok) === 1, 'el token es aleatorio de 128 bits (32 caracteres hexadecimales)');
$check((int) $scalar("SELECT COUNT(*) FROM bo_telegram_tokens WHERE token_hash = '" . hash('sha256', $tok) . "'") === 1
    && (int) $scalar("SELECT COUNT(*) FROM bo_telegram_tokens WHERE token_hash = '" . $tok . "'") === 0, 'en la base solo se guarda el hash, no el token en claro');
$check($fails(fn () => $svc->linkTelegramByToken('ZAP-20261030-0001', '555000111'), 'validation_failed'), 'el código del pedido ya no sirve como token');
$check($fails(fn () => $svc->linkTelegramByToken(str_repeat('a', 32), '555000111'), 'token_not_found'), 'un token desconocido se rechaza');
$r = $svc->linkTelegramByToken($tok, '555000111');
$check($r['result'] === 'linked' && $r['order_code'] === 'ZAP-20261030-0001', 'el token válido vincula el chat con su pedido');
$check($scalar('SELECT telegram_chat_id FROM bo_customers LIMIT 1') === '555000111', 'el chat queda guardado en el cliente');
$check($fails(fn () => $svc->linkTelegramByToken($tok, '555000111'), 'token_used'), 'el token no se puede reutilizar');
$t1 = $svc->issueTelegramToken('ZAP-20261030-0001');
$t2 = $svc->issueTelegramToken('ZAP-20261030-0001');
$check($fails(fn () => $svc->linkTelegramByToken($t1, '555000222'), 'token_expired'), 'un token nuevo invalida el anterior del mismo pedido');
$check($svc->linkTelegramByToken($t2, '555000222')['result'] === 'linked', 'el último token emitido sí funciona');
$t3 = $svc->issueTelegramToken('ZAP-20261030-0001');
$pdo->exec("UPDATE bo_telegram_tokens SET expires_at = '2000-01-01T00:00:00Z' WHERE token_hash = '" . hash('sha256', $t3) . "'");
$check($fails(fn () => $svc->linkTelegramByToken($t3, '555000333'), 'token_expired'), 'un token caducado se rechaza');
$t4 = $svc->issueTelegramToken('ZAP-20261030-0999');
$check($fails(fn () => $svc->linkTelegramByToken($t4, '555000444'), 'order_not_synced'), 'si el pedido aún no está en el back-office se avisa (409)');
$check($scalar("SELECT used_at FROM bo_telegram_tokens WHERE token_hash = '" . hash('sha256', $t4) . "'") === null, 'y el token NO se consume, para poder reintentar');
$check((int) $scalar("SELECT COUNT(*) FROM bo_integration_log WHERE event_type = 'telegram.link' AND status = 'rejected'") >= 4, 'los rechazos quedan en el log de integración');
$svc->setState('events.cursor', '42');
$check($svc->getState('events.cursor') === '42', 'guarda y lee el cursor de eventos');

// Limpieza
foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo $failures === 0 ? "\nTodo correcto.\n" : "\n$failures comprobación(es) fallida(s).\n";
exit($failures === 0 ? 0 : 1);
