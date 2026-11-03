-- Back-office de Zapatero (sistema interno SEPARADO de la tienda): base de datos propia.
-- Importes en céntimos, fechas ISO 8601 UTC. Datos exclusivamente ficticios.

CREATE TABLE bo_customers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    email            TEXT NOT NULL UNIQUE,
    full_name        TEXT NOT NULL,
    city             TEXT,
    province         TEXT,
    telegram_chat_id TEXT,
    created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    updated_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE bo_orders (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    order_code        TEXT NOT NULL UNIQUE,
    customer_id       INTEGER NOT NULL REFERENCES bo_customers (id),
    status            TEXT NOT NULL CHECK (status IN ('created', 'paid_simulated', 'pending_preparation', 'shipped', 'incident', 'cancelled')),
    currency          TEXT NOT NULL DEFAULT 'EUR',
    subtotal_cents    INTEGER NOT NULL,
    discount_cents    INTEGER NOT NULL DEFAULT 0,
    shipping_cents    INTEGER NOT NULL DEFAULT 0,
    tax_cents         INTEGER NOT NULL DEFAULT 0,
    total_cents       INTEGER NOT NULL,
    payment_status    TEXT,
    payment_reference TEXT,
    ordered_at        TEXT NOT NULL,
    status_updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    last_event_id     TEXT,
    synced_at         TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE bo_order_lines (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id         INTEGER NOT NULL REFERENCES bo_orders (id) ON DELETE CASCADE,
    sku              TEXT NOT NULL,
    name             TEXT NOT NULL,
    size_eu          INTEGER NOT NULL,
    quantity         INTEGER NOT NULL CHECK (quantity > 0),
    unit_price_cents INTEGER NOT NULL
);

CREATE TABLE bo_tickets (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_ref      TEXT NOT NULL UNIQUE,
    kind            TEXT NOT NULL CHECK (kind IN ('support', 'incident')),
    order_id        INTEGER NOT NULL REFERENCES bo_orders (id),
    customer_id     INTEGER NOT NULL REFERENCES bo_customers (id),
    source_event_id TEXT,
    subject         TEXT NOT NULL,
    message         TEXT NOT NULL,
    category        TEXT,
    priority        TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    status          TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'resolved')),
    created_at      TEXT NOT NULL,
    updated_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    resolved_at     TEXT
);

-- Idempotencia: un event_id solo se aplica una vez.
CREATE TABLE bo_processed_events (
    event_id     TEXT PRIMARY KEY,
    event_type   TEXT NOT NULL,
    processed_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- Trazabilidad: una fila por intento de integración (correcto, duplicado, rechazado o con error).
CREATE TABLE bo_integration_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id     TEXT,
    event_type   TEXT,
    workflow     TEXT,
    execution_id TEXT,
    status       TEXT NOT NULL CHECK (status IN ('ok', 'duplicate', 'rejected', 'error', 'pending_sync', 'stale', 'skipped')),
    error_code   TEXT,
    message      TEXT,
    entity_type  TEXT,
    entity_ref   TEXT,
    created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

-- Eventos recibidos antes de que exista el pedido al que se refieren (Escenario 2).
CREATE TABLE bo_pending_sync (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id   TEXT NOT NULL UNIQUE,
    event_type TEXT NOT NULL,
    order_code TEXT NOT NULL,
    payload    TEXT NOT NULL CHECK (json_valid(payload)),
    reason     TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'synced')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    synced_at  TEXT
);

-- Propuestas del agente de IA y su ciclo de revisión humana.
CREATE TABLE bo_agent_actions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_ref      TEXT REFERENCES bo_tickets (ticket_ref),
    order_code      TEXT NOT NULL,
    case_type       TEXT NOT NULL,
    consulted_data  TEXT NOT NULL CHECK (json_valid(consulted_data)),
    action_type     TEXT NOT NULL CHECK (action_type IN ('customer_message', 'status_change_proposal', 'internal_task', 'no_action')),
    action_payload  TEXT NOT NULL CHECK (json_valid(action_payload)),
    justification   TEXT NOT NULL,
    confidence      REAL NOT NULL CHECK (confidence >= 0 AND confidence <= 1),
    priority        TEXT NOT NULL CHECK (priority IN ('low', 'normal', 'high', 'urgent')),
    risks           TEXT NOT NULL CHECK (json_valid(risks)),
    model           TEXT,
    status          TEXT NOT NULL DEFAULT 'pending_review' CHECK (status IN (
                        'pending_review', 'approved', 'rejected', 'executed', 'executed_simulated', 'execution_failed')),
    created_at      TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    decided_at      TEXT,
    decided_by      TEXT,
    decision_note   TEXT,
    executed_at     TEXT,
    execution_note  TEXT
);

-- Pares clave/valor de estado de la integración (cursor de eventos, offset de Telegram).
CREATE TABLE bo_sync_state (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE INDEX idx_bo_orders_customer ON bo_orders (customer_id);
CREATE INDEX idx_bo_orders_status ON bo_orders (status);
CREATE INDEX idx_bo_tickets_order ON bo_tickets (order_id);
CREATE INDEX idx_bo_tickets_status ON bo_tickets (status);
CREATE INDEX idx_bo_log_status ON bo_integration_log (status);
CREATE INDEX idx_bo_agent_status ON bo_agent_actions (status);
CREATE INDEX idx_bo_pending_order ON bo_pending_sync (order_code, status);
