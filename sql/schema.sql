-- ===== Yenten-Sugar Exchange Schema (SQLite) =====

PRAGMA foreign_keys = ON;

-- Users
CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    email         TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    lang          TEXT NOT NULL DEFAULT 'en',
    theme         TEXT NOT NULL DEFAULT 'dark',
    created_at    INTEGER NOT NULL,
    last_login_at INTEGER
);

-- Per-user balances (satoshis)
CREATE TABLE IF NOT EXISTS user_wallets (
    user_id  INTEGER NOT NULL,
    coin     TEXT NOT NULL,           -- YTN or SUGAR
    balance  INTEGER NOT NULL DEFAULT 0,
    locked   INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, coin),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Deposits (for trading section; user wallet top-ups)
CREATE TABLE IF NOT EXISTS deposits (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    coin         TEXT NOT NULL,
    address      TEXT NOT NULL,
    amount_sat   INTEGER NOT NULL DEFAULT 0,
    txid         TEXT,
    height       INTEGER NOT NULL DEFAULT 0,
    confirmations INTEGER NOT NULL DEFAULT 0,
    status       TEXT NOT NULL DEFAULT 'pending',  -- pending|confirmed|failed
    created_at   INTEGER NOT NULL,
    confirmed_at INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_deposits_status ON deposits(status);
CREATE INDEX IF NOT EXISTS idx_deposits_address ON deposits(address);

-- Withdrawals
CREATE TABLE IF NOT EXISTS withdrawals (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    coin         TEXT NOT NULL,
    address      TEXT NOT NULL,
    amount_sat   INTEGER NOT NULL,
    txid         TEXT,
    fee_rate     INTEGER,
    status       TEXT NOT NULL DEFAULT 'pending',  -- pending|sending|sent|completed|failed
    error        TEXT,
    created_at   INTEGER NOT NULL,
    sent_at      INTEGER,
    completed_at INTEGER,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_withdrawals_status ON withdrawals(status);

-- Swap orders (instant exchange)
CREATE TABLE IF NOT EXISTS swap_orders (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    ref               TEXT UNIQUE,
    from_coin         TEXT NOT NULL,
    to_coin           TEXT NOT NULL,
    from_amount_sat   INTEGER NOT NULL,
    to_amount_sat     INTEGER NOT NULL,
    rate              TEXT NOT NULL,
    fee_percent       REAL NOT NULL,
    payout_address    TEXT NOT NULL,
    deposit_address   TEXT NOT NULL,
    deposit_index     INTEGER NOT NULL DEFAULT 0,
    deposit_txid      TEXT,
    deposit_height    INTEGER NOT NULL DEFAULT 0,
    confirmations     INTEGER NOT NULL DEFAULT 0,
    payout_txid       TEXT,
    payout_fee_rate   INTEGER,
    status            TEXT NOT NULL DEFAULT 'pending',
    -- pending|confirmed|sending|sent|completed|failed
    error             TEXT,
    created_at        INTEGER NOT NULL,
    confirmed_at      INTEGER,
    sent_at           INTEGER,
    completed_at      INTEGER
);
CREATE INDEX IF NOT EXISTS idx_swap_status ON swap_orders(status);
CREATE INDEX IF NOT EXISTS idx_swap_deposit ON swap_orders(deposit_address);

-- Trade orders (limit + market)
CREATE TABLE IF NOT EXISTS orders (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    pair         TEXT NOT NULL,              -- 'YTN/SUGAR'
    side         TEXT NOT NULL,              -- buy|sell
    type         TEXT NOT NULL,              -- limit|market
    price_sat    INTEGER NOT NULL DEFAULT 0, -- price per 1 base unit (in quote sats)
    amount_sat   INTEGER NOT NULL,           -- base amount
    filled_sat   INTEGER NOT NULL DEFAULT 0,
    status       TEXT NOT NULL DEFAULT 'open', -- open|matching|partial|filled|cancelled
    created_at   INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_pair_side ON orders(pair, side, status);
CREATE INDEX IF NOT EXISTS idx_orders_price ON orders(price_sat);

-- Trades (executed matches)
CREATE TABLE IF NOT EXISTS trades (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    maker_order_id   INTEGER NOT NULL,
    taker_order_id   INTEGER NOT NULL,
    price_sat        INTEGER NOT NULL,
    amount_sat       INTEGER NOT NULL,
    side             TEXT NOT NULL,
    created_at       INTEGER NOT NULL,
    FOREIGN KEY (maker_order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (taker_order_id) REFERENCES orders(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_trades_created ON trades(created_at);

-- Candles (1m by default)
CREATE TABLE IF NOT EXISTS candles (
    interval TEXT NOT NULL,
    ts       INTEGER NOT NULL,
    open     INTEGER NOT NULL,
    high     INTEGER NOT NULL,
    low      INTEGER NOT NULL,
    close    INTEGER NOT NULL,
    volume   INTEGER NOT NULL,
    PRIMARY KEY (interval, ts)
);

-- HD wallet state
CREATE TABLE IF NOT EXISTS hot_wallets (
    coin        TEXT PRIMARY KEY,
    xpub        TEXT,
    last_index  INTEGER NOT NULL DEFAULT 0,
    updated_at  INTEGER NOT NULL DEFAULT 0
);

-- Audit log
CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    action     TEXT NOT NULL,
    details    TEXT,
    ip         TEXT,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);
