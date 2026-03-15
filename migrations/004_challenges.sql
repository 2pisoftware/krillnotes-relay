-- This Source Code Form is subject to the terms of the Mozilla Public
-- License, v. 2.0. If a copy of the MPL was not distributed with this
-- file, You can obtain one at https://mozilla.org/MPL/2.0/.
--
-- Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

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
