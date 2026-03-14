CREATE TABLE bundles (
    bundle_id TEXT PRIMARY KEY,
    workspace_id TEXT NOT NULL,
    sender_device_key TEXT NOT NULL,
    recipient_device_key TEXT NOT NULL,
    mode TEXT NOT NULL
        CHECK (mode IN ('invite', 'accept', 'snapshot', 'delta')),
    size_bytes INTEGER NOT NULL,
    blob_path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_bundles_recipient ON bundles(recipient_device_key);
