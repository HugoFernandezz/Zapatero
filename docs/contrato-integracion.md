# Contrato de integración Zapatero → n8n → Back-office (v1)

Prototipo académico. Todos los datos son ficticios. Este documento es la referencia del contrato de datos (Tarea 2).

## 1. Principios

- **Pull con cursor**: n8n consulta `GET /api/v1/events?after_id=N` y guarda el último `next_after_id`. No hace falta que la web llame a n8n, así que n8n puede ejecutarse en cualquier equipo sin IP pública.
- **At-least-once + idempotencia**: un evento puede llegar más de una vez; el consumidor deduplica por `event_id` (el back-office tiene `UNIQUE(event_id)`).
- **Versionado**: cada evento lleva `schema_version`. Un cambio incompatible incrementa la versión y el consumidor rechaza las que no conoce (rama de error).
- **Minimización**: los eventos solo llevan identificadores y magnitudes; los datos completos se piden a `/orders/{code}`.

## 2. Autenticación

`Authorization: Bearer <API_TOKEN>` (variable de entorno; nunca en el repositorio).

| Código | `error` | Cuándo |
|---|---|---|
| 401 | `unauthorized` | Token ausente o inválido |
| 503 | `api_disabled` | `API_TOKEN` no configurado o < 24 caracteres |
| 400 | `invalid_after_id` / `invalid_limit` / `invalid_type` / `invalid_order_code` | Parámetro mal formado |
| 404 | `order_not_found` | Pedido inexistente |

## 3. `GET /api/v1/events`

Parámetros: `after_id` (entero ≥ 0, def. 0), `limit` (1–200, def. 100), `type` (opcional).

```json
{
  "api_version": "v1",
  "count": 2,
  "has_more": false,
  "next_after_id": 118,
  "events": [
    {
      "event_id": "evt_3f9c1a7e0b2d4c58a1e6f09b7d3c2a41",
      "cursor_id": 117,
      "schema_version": 1,
      "type": "order.created",
      "occurred_at": "2026-10-30T10:15:02Z",
      "session_id": "k3j2h1...",
      "user_id": 2,
      "payload": { "order_code": "ZAP-20261030-0003", "total_cents": 12990, "items": 2 }
    }
  ]
}
```

### Payloads por tipo (v1)

| `type` | Campos obligatorios en `payload` |
|---|---|
| `order.created` | `order_code` (ZAP-AAAAMMDD-NNNN), `total_cents` (int ≥ 0), `items` (int ≥ 1) |
| `payment.simulated` | `order_code`, `result` (`approved`\|`rejected`), `reference` (SIM-…) |
| `order.status_changed` | `order_code`, `from`, `to` (created, paid_simulated, pending_preparation, shipped, incident, cancelled) |
| `support.requested` | `order_code`, `ticket_id` (int), `subject_key`, `subject`, `message_length` |
| `incident.created` | `order_code`, `motivo` (string\|null) |
| `product.viewed`, `cart.item_added`, `checkout.started` | Solo analítica; el flujo de back-office los ignora (se usan en métricas) |

## 4. `GET /api/v1/orders/{code}`

Devuelve el pedido normalizado: `status`, importes en céntimos, `customer` (nombre, email, ciudad, provincia; de prueba), `items[]` (sku, name, size_eu, quantity, unit_price_cents), `payment` y `tickets[]`.

## 5. Reglas de validación del consumidor (n8n)

1. `event_id` coincide con `^evt_[0-9a-f]{32}$`.
2. `schema_version == 1`.
3. `type` pertenece al catálogo soportado.
4. Campos obligatorios del payload presentes y con tipo correcto.
5. `order_code` con formato válido.

Si falla cualquiera → rama de error: se registra en `integration_log` del back-office con `status=rejected` y el motivo, y no se crea ninguna entidad.

---

# Parte B · API del back-office (`/bo/api/v1`)

Autenticación: `Authorization: Bearer <BO_API_TOKEN>` (o `X-Api-Token`). Todas las escrituras usan **POST** (sin PATCH/PUT) y cuerpo JSON con **enteros reales** para los céntimos (un `"100"` se rechaza). Cada escritura lleva `event_id` (clave de idempotencia) y opcionalmente `event_type`, `workflow` y `execution_id`, que quedan en `bo_integration_log`.

| Método y ruta | Función | Respuestas |
|---|---|---|
| `POST /orders` | Crea/actualiza pedido + cliente + líneas. Body: `{event_id, event_type, workflow, execution_id, order:{…contrato de GET /api/v1/orders/{code}…}}` | 201 `created` · 200 `updated`/`duplicate` · 422 `validation_failed` |
| `POST /orders/{code}/status` | Cambio de estado. Body: `{event_id, status, occurred_at}` | 200 `updated`/`stale`/`duplicate` · 202 `pending_sync` (pedido aún no existe) |
| `GET /orders/{code}` | Herramienta de consulta del agente: pedido, líneas, tickets | 200 · 404 |
| `GET /customers/{id}` | Herramienta de consulta: cliente, pedidos e historial de incidencias | 200 · 404 |
| `POST /tickets` | Ticket de soporte/incidencia. Body: `{event_id, ticket:{ticket_ref, kind, order_code, subject, message, priority?, category?, created_at?}}` | 201 · 200 `duplicate` · 202 `pending_sync` |
| `POST /tickets/{ref}` | Actualiza `status` (open/in_progress/resolved), `priority`, `category` | 200 · 404 · 422 |
| `POST /agent-actions` | Registra una **propuesta** del agente (siempre nace `pending_review`) | 201 `{id,status}` · 422 |
| `GET /agent-actions?status=approved` | n8n recoge las aprobadas por una persona | 200 |
| `POST /agent-actions/{id}/executed` | n8n informa `{result: sent\|simulated\|failed, note}`; solo si está aprobada | 200 · 409 `not_approved` |
| `POST /telegram/link` | `{token, chat_id}` vincula el chat al cliente con el **token de un solo uso** del enlace `t.me/BOT?start=TOKEN` (128 bits, caduca a los 30 min, solo se guarda su hash). `404 token_not_found` · `410 token_used` / `token_expired` · `409 order_not_synced` (el token no se consume) · `422` formato | 200 |
| `POST /integration-log` | n8n escribe sus propios resultados/errores | 201 |
| `GET/POST /state/{key}` | Cursor de eventos y offset de Telegram | 200 |
| `GET /pending-sync` | Eventos a la espera de su pedido | 200 |
| `GET /metrics` | Indicadores operativos calculados con datos reales (los mismos del cuadro de mando `/bo/metricas`) | 200 |

Reglas: `event_id` repetido → `duplicate` sin efectos; evento más antiguo que el último cambio de estado → `stale` (se ignora y se registra); evento sobre un pedido desconocido → `pending_sync` y se reaplica automáticamente cuando llega el pedido.

## Salida estructurada del agente (`POST /agent-actions`)

```json
{
  "ticket_ref": "SUP-12", "order_code": "ZAP-20261030-0003",
  "case_type": "size_change",
  "consulted_data": ["pedido ZAP-20261030-0003", "historial de incidencias del cliente 4"],
  "action": {"type": "customer_message", "message": "Hola, hemos recibido tu solicitud…"},
  "justification": "El cliente pide cambio de talla y el pedido está en preparación.",
  "confidence": 0.86, "priority": "normal", "risks": [], "model": "gemini-…"
}
```

`case_type`: delivery_issue, damaged_product, size_change, invoice_issue, return_request, incident, other · `action.type`: customer_message, status_change_proposal, internal_task, no_action · **prohibido**: proponer `cancelled`, reembolsos o cualquier acción fuera de la lista (422). Con `confidence < 0.6` la prioridad sube a `high` y se añade un riesgo automáticamente. Estados finales: `pending_review`, `approved`, `rejected`, `executed`, `executed_simulated`, `execution_failed`.
