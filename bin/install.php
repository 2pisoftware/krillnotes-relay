<?php

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
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
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
