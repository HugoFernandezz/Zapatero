-- Esquema SQLite de Zapatero. Importes en céntimos (INTEGER) y fechas en ISO 8601 UTC.

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT    NOT NULL UNIQUE,
    name          TEXT    NOT NULL,
    password_hash TEXT    NOT NULL,
    role          TEXT    NOT NULL DEFAULT 'customer' CHECK (role IN ('customer', 'admin')),
    created_at    TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE collections (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    slug        TEXT NOT NULL UNIQUE,
    description TEXT
);

CREATE TABLE styles (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE
);

CREATE TABLE products (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    collection_id INTEGER NOT NULL REFERENCES collections (id),
    style_id      INTEGER NOT NULL REFERENCES styles (id),
    name          TEXT    NOT NULL,
    slug          TEXT    NOT NULL UNIQUE,
    description   TEXT    NOT NULL,
    material      TEXT    NOT NULL,
    price_cents   INTEGER NOT NULL CHECK (price_cents >= 0),
    image         TEXT
);

CREATE TABLE product_variants (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL REFERENCES products (id) ON DELETE CASCADE,
    size_eu    INTEGER NOT NULL CHECK (size_eu BETWEEN 36 AND 46),
    stock      INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    sku        TEXT    NOT NULL UNIQUE,
    UNIQUE (product_id, size_eu)
);

CREATE TABLE discount_codes (
    id     INTEGER PRIMARY KEY AUTOINCREMENT,
    code   TEXT    NOT NULL UNIQUE,
    type   TEXT    NOT NULL CHECK (type IN ('percent', 'fixed')),
    value  INTEGER NOT NULL CHECK (value > 0),
    active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1))
);

CREATE TABLE orders (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    code             TEXT    NOT NULL UNIQUE,
    user_id          INTEGER REFERENCES users (id),
    discount_code_id INTEGER REFERENCES discount_codes (id),
    status           TEXT    NOT NULL DEFAULT 'created' CHECK (status IN (
                         'created', 'paid_simulated', 'pending_preparation',
                         'shipped', 'incident', 'cancelled')),
    subtotal_cents   INTEGER NOT NULL,
    discount_cents   INTEGER NOT NULL DEFAULT 0,
    shipping_cents   INTEGER NOT NULL DEFAULT 0,
    tax_cents        INTEGER NOT NULL,
    total_cents      INTEGER NOT NULL,
    shipping_address TEXT    NOT NULL,
    created_at       TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE order_items (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id         INTEGER NOT NULL REFERENCES orders (id) ON DELETE CASCADE,
    variant_id       INTEGER NOT NULL REFERENCES product_variants (id),
    quantity         INTEGER NOT NULL CHECK (quantity > 0),
    unit_price_cents INTEGER NOT NULL
);

CREATE TABLE payments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES orders (id),
    method     TEXT    NOT NULL,
    status     TEXT    NOT NULL CHECK (status IN ('approved', 'rejected')),
    reference  TEXT    NOT NULL,
    card_last4 TEXT,
    created_at TEXT    NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE support_tickets (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER REFERENCES orders (id),
    subject    TEXT NOT NULL,
    message    TEXT NOT NULL,
    status     TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
);

CREATE TABLE events (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    type        TEXT NOT NULL,
    occurred_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
    session_id  TEXT,
    user_id     INTEGER REFERENCES users (id),
    payload     TEXT NOT NULL DEFAULT '{}' CHECK (json_valid(payload))
);

CREATE INDEX idx_products_collection ON products (collection_id);
CREATE INDEX idx_products_style ON products (style_id);
CREATE INDEX idx_orders_status ON orders (status);
CREATE INDEX idx_events_type ON events (type);
CREATE INDEX idx_events_occurred_at ON events (occurred_at);
