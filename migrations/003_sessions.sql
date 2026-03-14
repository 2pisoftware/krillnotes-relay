CREATE TABLE sessions (
    token TEXT PRIMARY KEY,
    account_id TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL
);
CREATE INDEX idx_sessions_account ON sessions(account_id);
