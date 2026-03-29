-- This Source Code Form is subject to the terms of the Mozilla Public
-- License, v. 2.0. If a copy of the MPL was not distributed with this
-- file, You can obtain one at https://mozilla.org/MPL/2.0/.
--
-- Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

ALTER TABLE bundles ADD COLUMN recipient_device_id TEXT;
CREATE INDEX idx_bundles_device_id ON bundles(recipient_device_id);

ALTER TABLE device_keys ADD COLUMN device_id TEXT;
