<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupportRepository;
use InvalidArgumentException;

final class SupportService
{
    public function __construct(
        private readonly SupportRepository $support,
        private readonly EventService $events
    ) {
    }

    public function createRequest(
        ?string $orderCode,
        string $subject,
        string $message
    ): int {
        $orderId = null;
        $normalizedOrderCode = null;

        if ($orderCode !== null && trim($orderCode) !== '') {
            $normalizedOrderCode = strtoupper(trim($orderCode));

            $order = $this->support->findOrderByCode(
                $normalizedOrderCode
            );

            if ($order === null) {
                throw new InvalidArgumentException('El pedido indicado no existe.');
            }

            $orderId = (int) $order['id'];
        }

        $subject = trim($subject);
        $message = trim($message);

        if ($subject === '') {
            throw new InvalidArgumentException('El asunto es obligatorio.');
        }

        if ($message === '') {
            throw new InvalidArgumentException('El mensaje es obligatorio.');
        }

        $ticketId = $this->support->createTicket(
            $orderId,
            $subject,
            $message
        );

        $this->events->record(
            'support.requested',
            [
                'order_code' => $normalizedOrderCode,
                'motivo' => $message,
                'ticket_id' => $ticketId,
            ]
        );

        return $ticketId;
    }

    public function allTickets(): array
    {
        return $this->support->allTickets();
    }

    public function createIncident(
        int $ticketId
    ): void {
        $ticket = $this->support->findTicket($ticketId);

        if ($ticket === null) {
            throw new InvalidArgumentException('La incidencia no existe.');
        }

        if ($ticket['order_id'] === null) {
            throw new InvalidArgumentException(
                'La incidencia debe estar asociada a un pedido.'
            );
        }

        $order = $this->support->setOrderIncident(
            (int) $ticket['order_id']
        );

        if ($order === null) {
            throw new InvalidArgumentException(
                'El estado actual del pedido no permite crear una incidencia.'
            );
        }

        $this->events->record(
            'incident.created',
            [
                'order_code' => $order['code'],
                'motivo' => $ticket['message'],
                'ticket_id' => $ticketId,
            ]
        );
    }

    public function resolveIncident(
        int $ticketId
    ): void {
        $ticket = $this->support->findTicket($ticketId);

        if ($ticket === null) {
            throw new InvalidArgumentException('La incidencia no existe.');
        }

        if ($ticket['order_id'] === null) {
            throw new InvalidArgumentException(
                'La incidencia no está asociada a un pedido.'
            );
        }

        $order = $this->support->resolveIncident(
            (int) $ticket['order_id']
        );

        if ($order === null) {
            throw new InvalidArgumentException(
                'El pedido no está actualmente en estado incident.'
            );
        }

        $this->support->closeTicket($ticketId);

        $this->events->record(
            'incident.resolved',
            [
                'order_code' => $order['code'],
                'ticket_id' => $ticketId,
            ]
        );
    }
}