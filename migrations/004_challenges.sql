CREATE TABLE challenges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id TEXT
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    device_public_key TEXT NOT NULL,
    nonce TEXT NOT NULL,
    server_public_key TEXT NOT NULL,
    context TEXT NOT NULL
        CHECK (context IN ('registration', 'device_add')),
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
