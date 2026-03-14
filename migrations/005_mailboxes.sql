CREATE TABLE mailboxes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    workspace_id TEXT NOT NULL,
    registered_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, workspace_id)
);
CREATE INDEX idx_mailboxes_workspace ON mailboxes(workspace_id);
