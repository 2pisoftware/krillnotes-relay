CREATE TABLE device_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    device_public_key TEXT NOT NULL UNIQUE,
    verified INTEGER NOT NULL DEFAULT 0,
    added_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_device_keys_pubkey ON device_keys(device_public_key);
