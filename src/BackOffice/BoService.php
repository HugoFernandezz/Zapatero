<?php

declare(strict_types=1);

namespace App\BackOffice;

use Throwable;

/**
 * Reglas de negocio del back-office: validación del contrato, idempotencia por event_id,
 * sincronización de pedidos/estados/tickets y ciclo de vida de las propuestas del agente de IA.
 * Cada operación deja una fila en bo_integration_log (trazabilidad).
 */
final class BoService
{
    public const ORDER_STATUSES = ['created', 'paid_simulated', 'pending_preparation', 'shipped', 'incident', 'cancelled'];
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];
    public const TICKET_STATUSES = ['open', 'in_progress', 'resolved'];
    public const ACTION_TYPES = ['customer_message', 'status_change_proposal', 'internal_task', 'no_action'];
    public const CASE_TYPES = ['delivery_issue', 'damaged_product', 'size_change', 'invoice_issue', 'return_request', 'incident', 'other'];
    /** Estados que el agente NUNCA puede proponer (acciones irreversibles o con efecto económico). */
    public const FORBIDDEN_PROPOSED_STATUSES = ['cancelled'];

    private const EVENT_ID = '/^evt_[0-9a-f]{32}$/';
    private const ORDER_CODE = '/^ZAP-\d{8}-\d{4}$/';
    private const TICKET_REF = '/^[A-Z]{3}-[A-Za-z0-9-]{1,40}$/';
    private const TELEGRAM_TOKEN = '/^[0-9a-f]{32}$/';
    /** Vigencia del enlace de vinculación de Telegram (segundos). */
    public const TELEGRAM_TOKEN_TTL = 1800;

    public function __construct(private readonly BoRepository $repo) {}

    public function repo(): BoRepository
    {
        return $this->repo;
    }

    // =============== PEDIDOS ===============

    /**
     * Crea o actualiza un pedido (Escenario 1). Idempotente por event_id.
     * Body: {event_id, event_type?, workflow?, execution_id?, occurred_at?, order: {...contrato GET /api/v1/orders/{code}...}}
     *
     * @return array{result: string, order_code: string}
     */
    public function ingestOrder(array $body): array
    {
        $meta = $this->meta($body);
        $order = is_array($body['order'] ?? null) ? $body['order'] : [];
        $errors = $this->validateOrder($order) + $this->validateEventId($body);
        if ($errors !== []) {
            $this->reject($meta, 'order', (string) ($order['order_code'] ?? ''), $errors);
        }
        $code = (string) $order['order_code'];

        if ($this->repo->isProcessed($meta['event_id'])) {
            $this->repo->log($meta['event_id'], $meta['type'], 'duplicate', $meta['workflow'], $meta['execution'], null, 'Evento ya aplicado (idempotencia).', 'order', $code);

            return ['result' => 'duplicate', 'order_code' => $code];
        }

        $this->repo->begin();
        try {
            $c = $order['customer'];
            $customerId = $this->repo->upsertCustomer(
                mb_strtolower(trim((string) $c['email'])), trim((string) $c['full_name']),
                $this->nullable($c['city'] ?? null), $this->nullable($c['province'] ?? null)
            );
            $payment = is_array($order['payment'] ?? null) ? $order['payment'] : [];
            // Una foto más antigua que el último cambio de estado ya recibido no puede hacer retroceder el estado.
            $status = (string) $order['status'];
            $statusAt = $this->timestamp($order['status_updated_at'] ?? $order['created_at']);
            $current = $this->repo->order($code);
            if ($current !== null && $statusAt < (string) $current['status_updated_at']) {
                $status = (string) $current['status'];
                $statusAt = (string) $current['status_updated_at'];
            }
            [$orderId, $created] = $this->repo->upsertOrder([
                'order_code' => $code, 'customer_id' => $customerId, 'status' => $status, 'status_updated_at' => $statusAt,
                'currency' => (string) ($order['currency'] ?? 'EUR'),
                'subtotal_cents' => (int) $order['subtotal_cents'], 'discount_cents' => (int) $order['discount_cents'],
                'shipping_cents' => (int) $order['shipping_cents'], 'tax_cents' => (int) $order['tax_cents'],
                'total_cents' => (int) $order['total_cents'],
                'payment_status' => $this->nullable($payment['status'] ?? null), 'payment_reference' => $this->nullable($payment['reference'] ?? null),
                'ordered_at' => (string) $order['created_at'], 'event_id' => $meta['event_id'],
            ]);
            $this->repo->replaceLines($orderId, $order['items']);
            $this->repo->markProcessed($meta['event_id'], $meta['type']);
            $this->replayPending($code);
            $this->repo->log($meta['event_id'], $meta['type'], 'ok', $meta['workflow'], $meta['execution'], null,
                $created ? 'Pedido creado en el back-office.' : 'Pedido actualizado en el back-office.', 'order', $code);
            $this->repo->commit();
        } catch (Throwable $e) {
            $this->repo->rollback();
            $this->repo->log($meta['event_id'], $meta['type'], 'error', $meta['workflow'], $meta['execution'], 'internal_error', $e->getMessage(), 'order', $code);
            throw new BoException(500, 'internal_error', 'Error interno al guardar el pedido.');
        }

        return ['result' => $created ? 'created' : 'updated', 'order_code' => $code];
    }

    /**
     * Cambio de estado (Escenario 2). Si el pedido aún no existe, queda en bo_pending_sync.
     * Body: {event_id, occurred_at?, status, from?}
     *
     * @return array{result: string, order_code: string}
     */
    public function changeOrderStatus(string $code, array $body): array
    {
        $meta = $this->meta($body);
        $errors = $this->validateEventId($body);
        if (preg_match(self::ORDER_CODE, $code) !== 1) {
            $errors['order_code'] = 'Formato ZAP-AAAAMMDD-NNNN.';
        }
        if (!in_array($body['status'] ?? null, self::ORDER_STATUSES, true)) {
            $errors['status'] = 'Estado no válido.';
        }
        if ($errors !== []) {
            $this->reject($meta, 'order', $code, $errors);
        }
        if ($this->repo->isProcessed($meta['event_id'])) {
            $this->repo->log($meta['event_id'], $meta['type'], 'duplicate', $meta['workflow'], $meta['execution'], null, 'Evento ya aplicado.', 'order', $code);

            return ['result' => 'duplicate', 'order_code' => $code];
        }

        return $this->applyStatus($code, $body, $meta);
    }

    /** @param array<string, ?string> $meta */
    private function applyStatus(string $code, array $body, array $meta): array
    {
        $order = $this->repo->order($code);
        if ($order === null) {
            $this->repo->addPending($meta['event_id'], 'order.status_changed', $code, $body, 'Pedido aún no sincronizado en el back-office.');
            $this->repo->markProcessed($meta['event_id'], $meta['type']);
            $this->repo->log($meta['event_id'], $meta['type'], 'pending_sync', $meta['workflow'], $meta['execution'], 'order_missing',
                'Pedido inexistente: cambio de estado guardado como pendiente de sincronización.', 'order', $code);

            return ['result' => 'pending_sync', 'order_code' => $code];
        }
        $occurredAt = $this->timestamp($body['occurred_at'] ?? null);
        if ($occurredAt < (string) $order['status_updated_at']) {
            $this->repo->markProcessed($meta['event_id'], $meta['type']);
            $this->repo->log($meta['event_id'], $meta['type'], 'stale', $meta['workflow'], $meta['execution'], 'out_of_order',
                'Evento más antiguo que el último cambio de estado: ignorado.', 'order', $code);

            return ['result' => 'stale', 'order_code' => $code];
        }
        $this->repo->setOrderStatus($code, (string) $body['status'], $occurredAt, $meta['event_id']);
        $this->repo->markProcessed($meta['event_id'], $meta['type']);
        $this->repo->log($meta['event_id'], $meta['type'], 'ok', $meta['workflow'], $meta['execution'], null,
            'Estado actualizado a ' . $body['status'] . '.', 'order', $code);

        return ['result' => 'updated', 'order_code' => $code];
    }

    // =============== TICKETS ===============

    /**
     * Crea un ticket de soporte o incidencia asociado al pedido y cliente (Escenario 3). Idempotente.
     * Body: {event_id, ticket: {ticket_ref, kind, order_code, subject, message, category?, priority?, created_at?}}
     *
     * @return array{result: string, ticket_ref: string}
     */
    public function ingestTicket(array $body): array
    {
        $meta = $this->meta($body);
        $t = is_array($body['ticket'] ?? null) ? $body['ticket'] : [];
        $errors = $this->validateEventId($body);
        if (preg_match(self::TICKET_REF, (string) ($t['ticket_ref'] ?? '')) !== 1) {
            $errors['ticket.ticket_ref'] = 'Formato AAA-xxxx (p. ej. SUP-12).';
        }
        if (!in_array($t['kind'] ?? null, ['support', 'incident'], true)) {
            $errors['ticket.kind'] = 'support | incident.';
        }
        if (preg_match(self::ORDER_CODE, (string) ($t['order_code'] ?? '')) !== 1) {
            $errors['ticket.order_code'] = 'Formato ZAP-AAAAMMDD-NNNN.';
        }
        foreach (['subject' => 160, 'message' => 2000] as $f => $max) {
            $v = trim((string) ($t[$f] ?? ''));
            if ($v === '' || mb_strlen($v) > $max) {
                $errors["ticket.$f"] = "Obligatorio, máximo $max caracteres.";
            }
        }
        if (isset($t['priority']) && !in_array($t['priority'], self::PRIORITIES, true)) {
            $errors['ticket.priority'] = implode(' | ', self::PRIORITIES) . '.';
        }
        if ($errors !== []) {
            $this->reject($meta, 'ticket', (string) ($t['ticket_ref'] ?? ''), $errors);
        }
        $ref = (string) $t['ticket_ref'];

        if ($this->repo->isProcessed($meta['event_id']) || $this->repo->ticket($ref) !== null) {
            $this->repo->log($meta['event_id'], $meta['type'], 'duplicate', $meta['workflow'], $meta['execution'], null, 'Ticket ya registrado.', 'ticket', $ref);

            return ['result' => 'duplicate', 'ticket_ref' => $ref];
        }

        return $this->createTicket($t, $meta, $body);
    }

    /** @param array<string, ?string> $meta */
    private function createTicket(array $t, array $meta, array $body): array
    {
        $ref = (string) $t['ticket_ref'];
        $order = $this->repo->order((string) $t['order_code']);
        if ($order === null) {
            $this->repo->addPending($meta['event_id'], 'ticket', (string) $t['order_code'], $body, 'Ticket recibido antes que su pedido.');
            $this->repo->markProcessed($meta['event_id'], $meta['type']);
            $this->repo->log($meta['event_id'], $meta['type'], 'pending_sync', $meta['workflow'], $meta['execution'], 'order_missing',
                'Pedido inexistente: ticket guardado como pendiente de sincronización.', 'ticket', $ref);

            return ['result' => 'pending_sync', 'ticket_ref' => $ref];
        }
        $this->repo->insertTicket([
            'ref' => $ref, 'kind' => $t['kind'], 'order_id' => $order['id'], 'customer_id' => $order['customer_id'],
            'event' => $meta['event_id'], 'subject' => trim((string) $t['subject']), 'message' => trim((string) $t['message']),
            'category' => $this->nullable($t['category'] ?? null), 'priority' => (string) ($t['priority'] ?? 'normal'),
            'created_at' => $this->timestamp($t['created_at'] ?? null),
        ]);
        $this->repo->markProcessed($meta['event_id'], $meta['type']);
        $this->repo->log($meta['event_id'], $meta['type'], 'ok', $meta['workflow'], $meta['execution'], null, 'Ticket creado y asociado al pedido y al cliente.', 'ticket', $ref);

        return ['result' => 'created', 'ticket_ref' => $ref];
    }

    /** Actualiza estado, prioridad o categoría de un ticket. Body: {status?, priority?, category?} */
    public function updateTicket(string $ref, array $body): array
    {
        $ticket = $this->repo->ticket($ref);
        if ($ticket === null) {
            throw BoException::notFound('El ticket ' . $ref);
        }
        $errors = [];
        $fields = [];
        if (isset($body['status'])) {
            if (in_array($body['status'], self::TICKET_STATUSES, true)) {
                $fields['status'] = $body['status'];
            } else {
                $errors['status'] = implode(' | ', self::TICKET_STATUSES) . '.';
            }
        }
        if (isset($body['priority'])) {
            if (in_array($body['priority'], self::PRIORITIES, true)) {
                $fields['priority'] = $body['priority'];
            } else {
                $errors['priority'] = implode(' | ', self::PRIORITIES) . '.';
            }
        }
        if (isset($body['category'])) {
            $fields['category'] = mb_substr(trim((string) $body['category']), 0, 60);
        }
        if ($errors !== [] || $fields === []) {
            throw BoException::validation($errors !== [] ? $errors : ['body' => 'Nada que actualizar.']);
        }
        if (isset($fields['status'])) {
            $fields['resolved_at'] = $fields['status'] === 'resolved' ? gmdate('Y-m-d\TH:i:s\Z') : null;
        }
        $this->repo->updateTicket($ref, $fields);
        $this->repo->log(null, 'ticket.updated', 'ok', 'backoffice', null, null, 'Ticket actualizado: ' . implode(', ', array_keys($fields)), 'ticket', $ref);

        return ['result' => 'updated', 'ticket_ref' => $ref];
    }

    // =============== AGENTE DE IA ===============

    /**
     * Registra una PROPUESTA del agente. Siempre nace en pending_review: el agente no puede aprobarse a sí mismo.
     * Body: {ticket_ref?, order_code, case_type, consulted_data[], action:{type, message?, new_status?, task?},
     *        justification, confidence(0..1), priority, risks[], model?}
     *
     * @return array{id: int, status: string}
     */
    public function proposeAgentAction(array $b): array
    {
        $errors = [];
        if (preg_match(self::ORDER_CODE, (string) ($b['order_code'] ?? '')) !== 1) {
            $errors['order_code'] = 'Formato ZAP-AAAAMMDD-NNNN.';
        } elseif ($this->repo->order((string) $b['order_code']) === null) {
            $errors['order_code'] = 'El pedido no existe en el back-office.';
        }
        $ticketRef = $this->nullable($b['ticket_ref'] ?? null);
        if ($ticketRef !== null && $this->repo->ticket($ticketRef) === null) {
            $errors['ticket_ref'] = 'El ticket no existe.';
        }
        if (!in_array($b['case_type'] ?? null, self::CASE_TYPES, true)) {
            $errors['case_type'] = implode(' | ', self::CASE_TYPES) . '.';
        }
        $consulted = $b['consulted_data'] ?? null;
        if (!is_array($consulted) || $consulted === [] || count($consulted) > 20) {
            $errors['consulted_data'] = 'Lista no vacía (máx. 20) de las consultas realizadas.';
        }
        $action = is_array($b['action'] ?? null) ? $b['action'] : [];
        $type = $action['type'] ?? null;
        if (!in_array($type, self::ACTION_TYPES, true)) {
            $errors['action.type'] = implode(' | ', self::ACTION_TYPES) . ' (acción no permitida).';
        } elseif ($type === 'customer_message') {
            $m = trim((string) ($action['message'] ?? ''));
            if ($m === '' || mb_strlen($m) > 800) {
                $errors['action.message'] = 'Obligatorio, máximo 800 caracteres.';
            }
        } elseif ($type === 'status_change_proposal') {
            $s = $action['new_status'] ?? null;
            if (!in_array($s, self::ORDER_STATUSES, true)) {
                $errors['action.new_status'] = 'Estado no válido.';
            } elseif (in_array($s, self::FORBIDDEN_PROPOSED_STATUSES, true)) {
                $errors['action.new_status'] = 'Acción prohibida para el agente: ' . $s . '.';
            }
        } elseif ($type === 'internal_task' && trim((string) ($action['task'] ?? '')) === '') {
            $errors['action.task'] = 'Obligatorio.';
        }
        $just = trim((string) ($b['justification'] ?? ''));
        if (mb_strlen($just) < 10 || mb_strlen($just) > 1000) {
            $errors['justification'] = 'Entre 10 y 1000 caracteres.';
        }
        $conf = $b['confidence'] ?? null;
        if (!is_numeric($conf) || (float) $conf < 0 || (float) $conf > 1) {
            $errors['confidence'] = 'Número entre 0 y 1.';
        }
        if (!in_array($b['priority'] ?? null, self::PRIORITIES, true)) {
            $errors['priority'] = implode(' | ', self::PRIORITIES) . '.';
        }
        if (!is_array($b['risks'] ?? null)) {
            $errors['risks'] = 'Lista (puede ir vacía).';
        }
        if ($errors !== []) {
            $this->repo->log(null, 'agent.proposal', 'rejected', 'agent', null, 'validation_failed', json_encode($errors, JSON_UNESCAPED_UNICODE) ?: '', 'agent_action', null);
            throw BoException::validation($errors);
        }

        $risks = array_values(array_map(static fn ($r) => mb_substr((string) $r, 0, 200), $b['risks']));
        $priority = (string) $b['priority'];
        if ((float) $conf < 0.6) {
            // Guardarraíl: baja confianza => prioridad de revisión al menos alta y aviso explícito.
            $risks[] = 'Confianza baja (<0,6): revisar con especial cuidado.';
            if (in_array($priority, ['low', 'normal'], true)) {
                $priority = 'high';
            }
        }
        $payload = array_intersect_key($action, array_flip(['type', 'message', 'new_status', 'task']));
        $id = $this->repo->insertAgentAction([
            'ticket_ref' => $ticketRef, 'order_code' => $b['order_code'], 'case_type' => $b['case_type'],
            'consulted' => json_encode(array_values($consulted), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'action_type' => $type, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'justification' => $just, 'confidence' => (float) $conf, 'priority' => $priority,
            'risks' => json_encode($risks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'model' => $this->nullable($b['model'] ?? null),
        ]);
        $this->repo->log(null, 'agent.proposal', 'ok', 'agent', null, null, 'Propuesta #' . $id . ' (' . $type . ') pendiente de revisión humana.', 'agent_action', (string) $id);

        return ['id' => $id, 'status' => 'pending_review'];
    }

    /** Decisión humana desde la interfaz. $decision = approve|reject. */
    public function decide(int $id, string $decision, string $adminEmail, string $note): void
    {
        $a = $this->repo->agentAction($id);
        if ($a === null) {
            throw BoException::notFound('La propuesta');
        }
        if ($a['status'] !== 'pending_review') {
            throw BoException::conflict('invalid_transition', 'Solo se puede decidir sobre propuestas pendientes de revisión.');
        }
        $status = $decision === 'approve' ? 'approved' : 'rejected';
        $this->repo->decideAgentAction($id, $status, $adminEmail, mb_substr(trim($note), 0, 300));
        $this->repo->log(null, 'agent.decision', 'ok', 'backoffice', null, null, 'Propuesta #' . $id . ' ' . ($status === 'approved' ? 'aprobada' : 'rechazada') . ' por ' . $adminEmail . '.', 'agent_action', (string) $id);
    }

    /** n8n informa de la ejecución de una acción aprobada. $result = sent | simulated | failed. */
    public function reportExecution(int $id, string $result, string $note): array
    {
        $a = $this->repo->agentAction($id);
        if ($a === null) {
            throw BoException::notFound('La propuesta');
        }
        if ($a['status'] !== 'approved') {
            throw BoException::conflict('not_approved', 'Solo se ejecutan propuestas aprobadas por una persona.');
        }
        $map = ['sent' => 'executed', 'simulated' => 'executed_simulated', 'failed' => 'execution_failed'];
        if (!isset($map[$result])) {
            throw BoException::validation(['result' => 'sent | simulated | failed.']);
        }
        $this->repo->executeAgentAction($id, $map[$result], mb_substr($note, 0, 300));
        $this->repo->log(null, 'agent.execution', $result === 'failed' ? 'error' : 'ok', 'n8n', null, $result === 'failed' ? 'execution_failed' : null,
            'Propuesta #' . $id . ' → ' . $map[$result] . '.', 'agent_action', (string) $id);

        return ['id' => $id, 'status' => $map[$result]];
    }

    // =============== TELEGRAM / ESTADO / LOG ===============

    /**
     * Emite un token aleatorio de un solo uso (128 bits) para el enlace t.me/BOT?start=TOKEN.
     * Se devuelve en claro una única vez; en la base solo se guarda su hash SHA-256.
     */
    public function issueTelegramToken(string $orderCode): string
    {
        if (preg_match(self::ORDER_CODE, $orderCode) !== 1) {
            throw BoException::validation(['order_code' => 'Formato ZAP-AAAAMMDD-NNNN.']);
        }
        $token = bin2hex(random_bytes(16));
        $this->repo->createTelegramToken(hash('sha256', $token), $orderCode, gmdate('Y-m-d\TH:i:s\Z', time() + self::TELEGRAM_TOKEN_TTL));

        return $token;
    }

    /**
     * Vincula el chat de Telegram al cliente del pedido usando el token del enlace.
     * 404 token desconocido · 410 usado o caducado · 409 el pedido aún no está sincronizado (el token NO se consume).
     *
     * @return array{result: string, order_code: string}
     */
    public function linkTelegramByToken(string $token, string $chatId): array
    {
        $errors = [];
        if (preg_match(self::TELEGRAM_TOKEN, $token) !== 1) {
            $errors['token'] = 'Token de 32 caracteres hexadecimales.';
        }
        if (preg_match('/^-?\d{4,20}$/', $chatId) !== 1) {
            $errors['chat_id'] = 'Identificador numérico de Telegram.';
        }
        if ($errors !== []) {
            $this->linkRejected(422, 'validation_failed', 'Se requieren un token de un solo uso y un chat_id numérico válidos.', null);
        }

        $row = $this->repo->telegramToken(hash('sha256', $token));
        if ($row === null) {
            $this->linkRejected(404, 'token_not_found', 'El enlace de Telegram no existe.', null);
        }
        $orderCode = (string) $row['order_code'];
        if ($row['used_at'] !== null) {
            $this->linkRejected(410, 'token_used', 'El enlace de Telegram ya se usó.', $orderCode);
        }
        if ((string) $row['expires_at'] <= gmdate('Y-m-d\TH:i:s\Z')) {
            $this->linkRejected(410, 'token_expired', 'El enlace de Telegram ha caducado.', $orderCode);
        }
        $order = $this->repo->order($orderCode);
        if ($order === null) {
            $this->linkRejected(409, 'order_not_synced', 'El pedido aún no está sincronizado; el enlace sigue siendo válido.', $orderCode);
        }

        $this->repo->begin();
        try {
            $consumed = $this->repo->consumeTelegramToken((int) $row['id']);
            if ($consumed) {
                $this->repo->setTelegramChat((int) $order['customer_id'], $chatId);
                $this->repo->log(null, 'telegram.linked', 'ok', 'n8n', null, null, 'Chat de Telegram vinculado al cliente del pedido con enlace de un solo uso.', 'order', $orderCode);
            }
            $this->repo->commit();
        } catch (Throwable $e) {
            $this->repo->rollback();
            throw $e;
        }
        if (!$consumed) {
            $this->linkRejected(410, 'token_used', 'El enlace de Telegram ya se usó.', $orderCode);
        }

        return ['result' => 'linked', 'order_code' => $orderCode];
    }

    /** Registra el rechazo en el log de integración (trazabilidad) y lanza el error HTTP correspondiente. */
    private function linkRejected(int $status, string $code, string $message, ?string $orderCode): never
    {
        $this->repo->log(null, 'telegram.link', 'rejected', 'n8n', null, $code, $message, $orderCode !== null ? 'order' : null, $orderCode);

        throw new BoException($status, $code, $message);
    }

    public function writeLog(array $b): void
    {
        $status = $b['status'] ?? null;
        if (!in_array($status, ['ok', 'duplicate', 'rejected', 'error', 'pending_sync', 'stale', 'skipped'], true)) {
            throw BoException::validation(['status' => 'ok | duplicate | rejected | error | pending_sync | stale | skipped.']);
        }
        $this->repo->log(
            $this->nullable($b['event_id'] ?? null), $this->nullable($b['event_type'] ?? null), (string) $status,
            $this->nullable($b['workflow'] ?? null), $this->nullable($b['execution_id'] ?? null),
            $this->nullable($b['error_code'] ?? null), $this->nullable($b['message'] ?? null),
            $this->nullable($b['entity_type'] ?? null), $this->nullable($b['entity_ref'] ?? null)
        );
    }

    public function getState(string $key): ?string
    {
        $this->assertKey($key);

        return $this->repo->state($key);
    }

    public function setState(string $key, string $value): void
    {
        $this->assertKey($key);
        if (mb_strlen($value) > 200) {
            throw BoException::validation(['value' => 'Máximo 200 caracteres.']);
        }
        $this->repo->setState($key, $value);
    }

    private function assertKey(string $key): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,40}$/', $key) !== 1) {
            throw BoException::validation(['key' => 'Formato [a-z0-9_.-], máximo 40.']);
        }
    }

    // =============== consultas para el agente ===============

    /** @return array<string, mixed> */
    public function orderView(string $code): array
    {
        $o = $this->repo->order($code);
        if ($o === null) {
            throw BoException::notFound('El pedido ' . $code);
        }

        return [
            'order' => [
                'order_code' => $o['order_code'], 'status' => $o['status'], 'ordered_at' => $o['ordered_at'], 'currency' => $o['currency'],
                'total_cents' => (int) $o['total_cents'], 'payment_status' => $o['payment_status'], 'customer_id' => (int) $o['customer_id'],
                'lines' => $this->repo->lines((int) $o['id']),
                'tickets' => $this->repo->ticketsForOrder((int) $o['id']),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function customerView(int $id): array
    {
        $c = $this->repo->customer($id);
        if ($c === null) {
            throw BoException::notFound('El cliente');
        }

        return ['customer' => [
            'id' => (int) $c['id'], 'full_name' => $c['full_name'], 'email' => $c['email'], 'city' => $c['city'],
            'has_telegram' => !empty($c['telegram_chat_id']),
            'orders' => $this->repo->ordersForCustomer($id), 'ticket_history' => $this->repo->ticketsForCustomer($id),
        ]];
    }

    // =============== internos ===============

    /** Aplica en orden los eventos que esperaban a este pedido. */
    private function replayPending(string $code): void
    {
        foreach ($this->repo->pendingForOrder($code) as $p) {
            $payload = json_decode((string) $p['payload'], true) ?: [];
            $meta = ['event_id' => (string) $p['event_id'], 'type' => (string) $p['event_type'], 'workflow' => 'backoffice-replay', 'execution' => null];
            if ($p['event_type'] === 'ticket') {
                $this->createTicket((array) ($payload['ticket'] ?? []), $meta, $payload);
            } else {
                $order = $this->repo->order($code);
                $at = $this->timestamp($payload['occurred_at'] ?? null);
                if ($order !== null && isset($payload['status']) && $at >= (string) $order['status_updated_at']) {
                    $this->repo->setOrderStatus($code, (string) $payload['status'], $at, $meta['event_id']);
                }
                $this->repo->log($meta['event_id'], $meta['type'], 'ok', 'backoffice-replay', null, null, 'Cambio de estado pendiente aplicado tras sincronizar el pedido.', 'order', $code);
            }
            $this->repo->markPendingSynced((int) $p['id']);
        }
    }

    /** @return array<string, string> */
    private function validateOrder(array $o): array
    {
        $e = [];
        if (preg_match(self::ORDER_CODE, (string) ($o['order_code'] ?? '')) !== 1) {
            $e['order.order_code'] = 'Formato ZAP-AAAAMMDD-NNNN.';
        }
        if (!in_array($o['status'] ?? null, self::ORDER_STATUSES, true)) {
            $e['order.status'] = 'Estado no válido.';
        }
        foreach (['subtotal_cents', 'discount_cents', 'shipping_cents', 'tax_cents', 'total_cents'] as $f) {
            if (!isset($o[$f]) || !is_int($o[$f]) || $o[$f] < 0) {
                $e["order.$f"] = 'Entero >= 0 (céntimos).';
            }
        }
        if (!isset($o['created_at']) || !is_string($o['created_at']) || strtotime($o['created_at']) === false) {
            $e['order.created_at'] = 'Fecha ISO 8601.';
        }
        $c = is_array($o['customer'] ?? null) ? $o['customer'] : [];
        if (filter_var($c['email'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            $e['order.customer.email'] = 'Email válido obligatorio.';
        }
        if (trim((string) ($c['full_name'] ?? '')) === '') {
            $e['order.customer.full_name'] = 'Obligatorio.';
        }
        $items = $o['items'] ?? null;
        if (!is_array($items) || $items === []) {
            $e['order.items'] = 'Al menos una línea.';
        } else {
            foreach ($items as $i => $it) {
                if (!is_array($it) || trim((string) ($it['sku'] ?? '')) === '' || trim((string) ($it['name'] ?? '')) === ''
                    || !is_int($it['size_eu'] ?? null) || !is_int($it['quantity'] ?? null) || $it['quantity'] < 1
                    || !is_int($it['unit_price_cents'] ?? null) || $it['unit_price_cents'] < 0) {
                    $e["order.items[$i]"] = 'Requiere sku, name, size_eu, quantity >= 1 y unit_price_cents.';
                }
            }
        }

        return $e;
    }

    /** @return array<string, string> */
    private function validateEventId(array $body): array
    {
        return preg_match(self::EVENT_ID, (string) ($body['event_id'] ?? '')) === 1 ? [] : ['event_id' => 'Formato evt_ + 32 hex.'];
    }

    /** @return array{event_id: ?string, type: ?string, workflow: ?string, execution: ?string} */
    private function meta(array $body): array
    {
        return [
            'event_id' => $this->nullable($body['event_id'] ?? null),
            'type' => $this->nullable($body['event_type'] ?? null) ?? 'unknown',
            'workflow' => $this->nullable($body['workflow'] ?? null) !== null ? mb_substr((string) $body['workflow'], 0, 60) : null,
            'execution' => $this->nullable($body['execution_id'] ?? null) !== null ? mb_substr((string) $body['execution_id'], 0, 60) : null,
        ];
    }

    /** @param array<string, ?string> $meta @param array<string, string> $errors */
    private function reject(array $meta, string $entityType, string $ref, array $errors): never
    {
        $this->repo->log($meta['event_id'], $meta['type'], 'rejected', $meta['workflow'], $meta['execution'], 'validation_failed',
            json_encode($errors, JSON_UNESCAPED_UNICODE) ?: 'validation_failed', $entityType, $ref !== '' ? $ref : null);

        throw BoException::validation($errors);
    }

    private function nullable(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : $s;
    }

    private function timestamp(mixed $v): string
    {
        $t = is_string($v) ? strtotime($v) : false;

        return gmdate('Y-m-d\TH:i:s\Z', $t !== false ? $t : time());
    }
}
