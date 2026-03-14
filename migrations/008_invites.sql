CREATE TABLE invites (
    invite_id      TEXT PRIMARY KEY,
    token          TEXT NOT NULL UNIQUE,
    account_id     TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    blob_path      TEXT NOT NULL,
    size_bytes     INTEGER NOT NULL,
    expires_at     TEXT NOT NULL,
    download_count INTEGER NOT NULL DEFAULT 0,
    created_at     TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_invites_token ON invites(token);
CREATE INDEX idx_invites_account ON invites(account_id);
