<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

/**
 * First-time installation script.
 * Run via: php bin/install.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Relay\Database\Connection;
use Relay\Database\Migrator;

$settings = require __DIR__ . '/../config/settings.php';

// Ensure storage directories exist
$dirs = [
    dirname($settings['database']['path']),
    $settings['storage']['bundles_path'],
    $settings['storage']['invites_path'],
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0760, true);
        echo "Created: {$dir}\n";
    }
}

// Create database and run migrations
$pdo = Connection::create($settings['database']['path']);
$migrator = new Migrator($pdo, __DIR__ . '/../migrations');
$migrator->run();

echo "Database created and migrations applied.\n";

// Verify sodium extension
if (!extension_loaded('sodium')) {
    echo "WARNING: sodium extension not loaded. Proof-of-possession will not work.\n";
} else {
    echo "sodium extension: OK\n";
}

echo "\nInstallation complete. Point your web server's document root to public/\n";
