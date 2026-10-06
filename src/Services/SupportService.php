<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\OrderRepository;

/**
 * Solicitudes de soporte / postventa de un pedido. Guarda el ticket (support_tickets) y registra el
 * evento de negocio `support.requested`. El texto libre del cliente NO se copia al evento (solo su longitud).
 */
final class SupportService
{
    public const MIN_MESSAGE = 10;
    public const MAX_MESSAGE = 1000;
    /** Tope por pedido para evitar abuso del formulario. */
    public const MAX_TICKETS_PER_ORDER = 5;

    /** Clave => motivo mostrado. */
    public const SUBJECTS = [
        'delivery' => 'Estado o retraso de la entrega',
        'damaged' => 'Producto dañado o defectuoso',
        'size' => 'Cambio de talla',
        'invoice' => 'Problema con la factura',
        'other' => 'Otra consulta',
    ];

    public function __construct(
        private readonly OrderRepository $orders,
        private readonly EventService $events
    ) {}

    /**
     * @param array $order fila de pedido (OrderRepository::find)
     * @return array{errors: array<string,string>, ticket_id: ?int}
     */
    public function request(array $order, array $input, ?string $sessionId, ?int $userId = null): array
    {
        $key = (string) ($input['subject'] ?? '');
        $message = trim((string) ($input['message'] ?? ''));
        $errors = [];

        if (!isset(self::SUBJECTS[$key])) {
            $errors['subject'] = 'Elige un motivo de la lista.';
        }
        $length = mb_strlen($message);
        if ($length < self::MIN_MESSAGE) {
            $errors['message'] = 'Cuéntanos un poco más (mínimo ' . self::MIN_MESSAGE . ' caracteres).';
        } elseif ($length > self::MAX_MESSAGE) {
            $errors['message'] = 'El mensaje es demasiado largo (máximo ' . self::MAX_MESSAGE . ' caracteres).';
        }
        if ($errors === [] && $this->orders->countTickets((int) $order['id']) >= self::MAX_TICKETS_PER_ORDER) {
            $errors['message'] = 'Este pedido ya tiene el máximo de solicitudes abiertas. Te responderemos en cuanto sea posible.';
        }
        if ($errors !== []) {
            return ['errors' => $errors, 'ticket_id' => null];
        }

        $ticketId = $this->orders->insertTicket((int) $order['id'], self::SUBJECTS[$key], $message);
        $this->events->record('support.requested', [
            'order_code' => (string) $order['code'],
            'ticket_id' => $ticketId,
            'subject_key' => $key,
            'subject' => self::SUBJECTS[$key],
            'message_length' => $length,
        ], $sessionId, $userId);

        return ['errors' => [], 'ticket_id' => $ticketId];
    }
}
