<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;
use App\Support\OrderStatus;
use DomainException;
use PDO;
use Throwable;

/** Creación de pedidos con pago simulado, cambios de estado, factura por email y consultas para el back-office. */
final class OrderService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly OrderRepository $orders,
        private readonly EventService $events,
        private readonly InvoiceService $invoices,
        private readonly MailService $mail,
        private readonly ?OrderNotifier $notifier = null
    ) {}

    /**
     * Crea el pedido, registra el pago simulado y actualiza el estado en una única transacción.
     * Pago aprobado: descuenta stock y deja el pedido en paid_simulated.
     * Pago rechazado: el pedido queda cancelled y no se toca el stock.
     *
     * @param array $quote    Resumen de CartService::summary()
     * @param array $shipping Datos validados de CheckoutService::validate()
     * @param array $card     Resultado de PaymentService::evaluate()
     * @param ?int  $userId   Cliente con sesión iniciada (null si compra como invitado)
     * @return array{code: string, approved: bool, invoice_sent: bool}
     * @throws DomainException si no hay stock suficiente en el momento de pagar
     */
    public function placeOrder(array $quote, array $shipping, array $card, ?string $sessionId, ?int $userId = null): array
    {
        $approved = (bool) $card['approved'];
        $finalStatus = $approved ? OrderStatus::PAID : OrderStatus::CANCELLED;

        $this->begin();
        try {
            $code = $this->orders->nextCode(gmdate('Ymd'));
            $orderId = $this->orders->insertOrder([
                'code' => $code,
                'user_id' => $userId,
                'discount_code_id' => $this->orders->discountCodeId($quote['discount_code'] ?? null),
                'status' => OrderStatus::CREATED,
                'subtotal_cents' => (int) $quote['subtotal_cents'],
                'discount_cents' => (int) $quote['discount_cents'],
                'shipping_cents' => (int) $quote['shipping_cents'],
                'tax_cents' => (int) $quote['tax_cents'],
                'total_cents' => (int) $quote['total_cents'],
                'shipping_address' => json_encode($shipping, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);

            foreach ($quote['items'] as $item) {
                $quantity = (int) $item['quantity'];
                $this->orders->insertItem($orderId, (int) $item['variant_id'], $quantity, (int) $item['price_cents']);
                if ($approved && !$this->orders->decrementStock((int) $item['variant_id'], $quantity)) {
                    throw new DomainException(sprintf(
                        'Ya no queda stock suficiente de %s (talla EU %d). Revisa tu carrito.',
                        $item['name'],
                        (int) $item['size_eu']
                    ));
                }
            }

            $reference = 'SIM-' . strtoupper(bin2hex(random_bytes(5)));
            $this->orders->insertPayment($orderId, 'card_simulated', $approved ? 'approved' : 'rejected', $reference, $card['last4'] !== '' ? $card['last4'] : null);

            $this->events->record('order.created', [
                'order_code' => $code, 'total_cents' => (int) $quote['total_cents'], 'items' => count($quote['items']),
            ], $sessionId);
            $this->events->record('payment.simulated', [
                'order_code' => $code, 'result' => $approved ? 'approved' : 'rejected', 'reference' => $reference,
            ], $sessionId);

            $this->orders->updateStatus($orderId, $finalStatus);
            $this->events->record('order.status_changed', [
                'order_code' => $code, 'from' => OrderStatus::CREATED, 'to' => $finalStatus, 'by' => 'sistema',
            ], $sessionId, $userId);
            if ($approved && $userId !== null) {
                // Compra hecha: el carrito guardado del cliente ya no tiene sentido.
                $this->orders->clearUserCart($userId);
            }

            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        // Fuera de la transacción: un fallo de correo nunca debe deshacer un pedido ya pagado.
        $invoiceSent = $approved ? $this->sendInvoice($code, null, $sessionId) : false;

        return ['code' => $code, 'approved' => $approved, 'invoice_sent' => $invoiceSent];
    }

    /** Envía (o reenvía) la factura al email de entrega. Solo existe factura si el pago fue aprobado. */
    public function sendInvoice(string $code, ?int $adminId = null, ?string $sessionId = null): bool
    {
        $detail = $this->detail($code);
        if ($detail === null || ($detail['payment']['status'] ?? '') !== 'approved') {
            return false;
        }
        $to = (string) ($detail['customer']['email'] ?? '');

        try {
            $sent = $this->mail->send(
                $to,
                'Factura de tu pedido ' . $code . ' - Zapatero',
                $this->invoices->emailHtml($detail),
                $this->invoices->emailText($detail)
            );
        } catch (Throwable $e) {
            error_log('Zapatero: fallo al enviar factura ' . $code . ': ' . $e->getMessage());
            $sent = false;
        }

        $this->events->record($sent ? 'invoice.sent' : 'invoice.failed', ['order_code' => $code, 'to' => $to], $sessionId, $adminId);

        return $sent;
    }

    /**
     * Cambio manual de estado desde el back-office, validado contra la máquina de estados.
     * Tras guardar el cambio avisa al cliente por email.
     *
     * @return ?bool true = cliente avisado, false = el correo falló (el cambio de estado sí se guardó),
     *               null = ese estado no genera correo
     */
    public function changeStatus(string $code, string $to, int $adminId, string $adminEmail, string $note = ''): ?bool
    {
        $note = mb_substr(trim($note), 0, 200);

        $this->begin();
        try {
            $order = $this->orders->find($code);
            if ($order === null) {
                throw new DomainException('El pedido no existe.');
            }
            $from = (string) $order['status'];
            if (!in_array($to, OrderStatus::adminOptions($from), true)) {
                throw new DomainException(sprintf(
                    'No se puede pasar de «%s» a «%s».',
                    OrderStatus::label($from),
                    OrderStatus::isValid($to) ? OrderStatus::label($to) : $to
                ));
            }

            $this->orders->updateStatus((int) $order['id'], $to);
            // Si el pedido llegó a cobrarse (stock ya descontado), cancelar devuelve las unidades.
            if ($to === OrderStatus::CANCELLED && $from !== OrderStatus::CREATED) {
                $this->orders->restock((int) $order['id']);
            }

            $this->events->record('order.status_changed', [
                'order_code' => $code, 'from' => $from, 'to' => $to, 'by' => $adminEmail, 'note' => $note,
            ], null, $adminId);
            if ($to === OrderStatus::INCIDENT) {
                $this->events->record('incident.created', [
                    'order_code' => $code, 'motivo' => $note !== '' ? $note : null,
                ], null, $adminId);
            }

            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        // Fuera de la transacción: un fallo de correo nunca debe deshacer el cambio de estado ya guardado.
        return $this->notifyStatusChange($code, $from, $to, $adminId);
    }

    /** Avisa al cliente del nuevo estado de su pedido. null = ese estado no genera correo. */
    private function notifyStatusChange(string $code, string $from, string $to, int $adminId): ?bool
    {
        if ($this->notifier === null || !$this->notifier->handles($to)) {
            return null;
        }

        $recipient = '';
        try {
            $detail = $this->detail($code);
            $recipient = (string) ($detail['customer']['email'] ?? '');
            $sent = $detail !== null && $this->mail->send(
                $recipient,
                $this->notifier->subject($to, $code, $from),
                $this->notifier->html($detail, $to, $from),
                $this->notifier->text($detail, $to, $from)
            );
            $this->events->record(
                $sent ? 'status_email.sent' : 'status_email.failed',
                ['order_code' => $code, 'to' => $recipient, 'status' => $to],
                null,
                $adminId
            );
        } catch (Throwable $e) {
            error_log('Zapatero: fallo al notificar el estado de ' . $code . ': ' . $e->getMessage());
            $sent = false;
        }

        return $sent;
    }

    /** @return array{order: array, customer: array, items: array, payment: ?array, events: array, tickets: array}|null */
    public function detail(string $code): ?array
    {
        $order = $this->orders->find($code);
        if ($order === null) {
            return null;
        }
        $customer = json_decode((string) $order['shipping_address'], true);

        return [
            'order' => $order,
            'customer' => is_array($customer) ? $customer : [],
            'items' => $this->orders->items((int) $order['id']),
            'payment' => $this->orders->latestPayment((int) $order['id']),
            'events' => $this->orders->events($code),
            'tickets' => $this->orders->tickets((int) $order['id']),
        ];
    }

    /** Pedidos de un cliente para su área privada. */
    public function ordersForUser(int $userId): array
    {
        return $this->orders->forUser($userId);
    }

    /**
     * Historial de estados visible para el cliente (sin notas internas ni datos del administrador).
     *
     * @return list<array{status: string, at: string}>
     */
    public static function statusTimeline(array $events): array
    {
        $timeline = [];
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'order.status_changed') {
                continue;
            }
            $payload = json_decode((string) ($event['payload'] ?? ''), true);
            if (is_array($payload) && isset($payload['to']) && is_string($payload['to'])) {
                $timeline[] = ['status' => $payload['to'], 'at' => (string) $event['occurred_at']];
            }
        }

        return $timeline;
    }

    public function listOrders(?string $status, string $query, int $limit, int $offset): array
    {
        return $this->orders->search($status, $query, $limit, $offset);
    }

    public function countOrders(?string $status, string $query): int
    {
        return $this->orders->count($status, $query);
    }

    /** @return array<string,int> */
    public function statusCounts(): array
    {
        return $this->orders->statusCounts();
    }

    // BEGIN IMMEDIATE toma el bloqueo de escritura desde el inicio: serializa la numeración de pedidos y el stock.
    private function begin(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    private function commit(): void
    {
        $this->pdo->exec('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (Throwable) {
            // No había transacción activa (por ejemplo, falló el propio BEGIN).
        }
    }
}
