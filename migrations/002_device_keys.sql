-- This Source Code Form is subject to the terms of the Mozilla Public
-- License, v. 2.0. If a copy of the MPL was not distributed with this
-- file, You can obtain one at https://mozilla.org/MPL/2.0/.
--
-- Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

CREATE TABLE device_keys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id TEXT NOT NULL
        REFERENCES accounts(account_id) ON DELETE CASCADE,
    device_public_key TEXT NOT NULL UNIQUE,
    verified INTEGER NOT NULL DEFAULT 0,
    added_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_device_keys_pubkey ON device_keys(device_public_key);
