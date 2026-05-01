<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

return [
    'database' => [
        'path' => dirname(__DIR__) . '/storage/database/relay.sqlite',
    ],
    'storage' => [
        'bundles_path' => dirname(__DIR__) . '/storage/bundles',
        'invites_path' => dirname(__DIR__) . '/storage/invites',
    ],
    'auth' => [
        'session_lifetime_seconds' => 86400 * 30, // 30 days
        'challenge_lifetime_seconds' => 300,       // 5 minutes
        'reset_token_lifetime_seconds' => 3600,    // 1 hour
    ],
    'limits' => [
        'max_bundle_size_bytes' => 10 * 1024 * 1024,         // 10 MB
        'max_storage_per_account_bytes' => 100 * 1024 * 1024, // 100 MB
        'bundle_retention_days' => 30,
        'account_deletion_grace_days' => 90,
        'min_poll_interval_seconds' => 60,
    ],
];
