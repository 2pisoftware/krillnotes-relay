<?php
/**
 * One-time remote install trigger.
 * DELETE THIS FILE after running.
 * Access: /install-run.php?token=YOUR_TOKEN
 */

if (($_GET['token'] ?? '') !== '90327bd379390e800741e5b284d5dcb2') {
    http_response_code(403);
    exit('Forbidden');
}

require __DIR__ . '/../vendor/autoload.php';

use Relay\Database\Connection;
use Relay\Database\Migrator;

$settings = require __DIR__ . '/../config/settings.php';

$output = [];

// Create storage directories
foreach ([
    dirname($settings['database']['path']),
    $settings['storage']['bundles_path'],
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
        $output[] = "Created: $dir";
    } else {
        $output[] = "Exists:  $dir";
    }
}

// Run migrations
$pdo = Connection::create($settings['database']['path']);
$migrator = new Migrator($pdo, __DIR__ . '/../migrations');
$migrator->run();
$output[] = "Migrations: OK";

// Check sodium
$output[] = "sodium: " . (extension_loaded('sodium') ? 'OK' : 'WARNING — not loaded');

header('Content-Type: text/plain');
echo implode("\n", $output) . "\nDone. Delete this file now.\n";
