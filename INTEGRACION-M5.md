# M5 · Eventos, soporte y exportación

## Dónde se generan

`EventService::record($type, $payload, $sessionId, $userId)` inserta una fila en `events`. Se llama desde la capa que conoce el hecho de negocio, nunca desde las vistas:

| Evento | Origen en el código | Payload (JSON) |
|---|---|---|
| `product.viewed` | `ProductController::show` | `product_id`, `slug` |
| `cart.item_added` | `CartService::add` | `variant_id`, `size_eu`, `quantity` |
| `checkout.started` | `CheckoutController::show` | `items`, `subtotal_cents` |
| `order.created` | `OrderService::placeOrder` | `order_code`, `total_cents`, `items` |
| `payment.simulated` | `OrderService::placeOrder` | `order_code`, `result`, `reference` |
| `order.status_changed` | `placeOrder` y `OrderService::changeStatus` | `order_code`, `from`, `to`, `by`, `note` |
| `incident.created` | `OrderService::changeStatus` (estado «Con incidencia») | `order_code`, `motivo` |
| `support.requested` | `SupportService::request` | `order_code`, `ticket_id`, `subject_key`, `subject`, `message_length` |
| `invoice.sent` / `invoice.failed` | `OrderService::sendInvoice` | `order_code`, `to` |
| `status_email.sent` / `status_email.failed` | `OrderService::notifyStatusChange` | `order_code`, `to`, `status` |

El texto libre que escribe el cliente en soporte **no** se copia al evento (solo su longitud); queda en `support_tickets`.

## Dónde se almacenan

Tabla `events` (SQLite): `id`, `type`, `occurred_at` (UTC, ISO 8601), `session_id`, `user_id`, `payload` (JSON con `CHECK (json_valid(payload))`). Índices por `type` y por `occurred_at`. `session_id` permite reconstruir el embudo de una visita (ver → carrito → checkout → pedido).

## Cómo se consumen (Tarea 2)

1. **Pantalla** `/admin/eventos`: recuento por tipo, filtros por tipo, fecha y texto (código de pedido o sesión) y paginación.
2. **Exportación JSON** `/admin/eventos/exportar/json`:
   ```json
   { "exported_at": "2026-10-04T11:36:04Z", "count": 1,
     "events": [ { "id": 59, "type": "support.requested", "occurred_at": "2026-10-04T11:36:04Z",
                   "session_id": "…", "user_id": 1, "payload": { "order_code": "ZAP-20261004-0003", "ticket_id": 2 } } ] }
   ```
3. **Exportación CSV** `/admin/eventos/exportar/csv` (UTF-8 con BOM, columnas `id, occurred_at, type, session_id, user_id, payload`).
4. Ambas respetan los filtros activos (`?type=order.created&from=2026-10-01&to=2026-10-31`) y exportan como máximo 10 000 eventos.
5. Alternativa: un proceso externo puede leer `events` directamente (`WHERE id > :ultimo_id_procesado`), ya que el `id` es creciente.

Ejemplos de uso previsto: tasa de conversión del embudo, productos más vistos frente a más comprados, pedidos con incidencia y tiempo de resolución, o disparar automatizaciones (avisar a logística cuando llega `payment.simulated` con `approved`).

## Soporte postventa

El cliente (o la sesión que hizo el pedido) ve en `/pedido/{código}` el formulario «¿Necesitas ayuda con este pedido?» cuando el pedido está pagado. Valida motivo (lista cerrada), mensaje de 10 a 1000 caracteres y token CSRF, y admite como máximo 5 solicitudes por pedido. Guarda el ticket en `support_tickets` y genera `support.requested`. El administrador las ve en el detalle del pedido.
