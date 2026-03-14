<?php

/**
 * Cron cleanup script.
 * Run via: php bin/cleanup.php
 * Suggested cron: 0 * * * * php /path/to/relay/bin/cleanup.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\BundleRepository;
use Relay\Service\StorageService;

$settings = require __DIR__ . '/../config/settings.php';
$pdo = Connection::create($settings['database']['path']);

// Run migrations (safe — skips already applied)
(new Migrator($pdo, __DIR__ . '/../migrations'))->run();

$bundles = new BundleRepository($pdo);
$storage = new StorageService($settings['storage']['bundles_path']);

// 1. Delete expired bundles
$retentionDays = $settings['limits']['bundle_retention_days'];
$cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
$expired = $bundles->deleteExpiredBefore($cutoff);
foreach ($expired as $bundle) {
    $storage->delete($bundle['blob_path']);
}
$expiredCount = count($expired);

// 2. Delete expired sessions
$pdo->exec("DELETE FROM sessions WHERE expires_at <= datetime('now')");

// 3. Delete expired challenges
$pdo->exec("DELETE FROM challenges WHERE expires_at <= datetime('now')");

// 4. Delete expired password reset tokens
$pdo->exec("DELETE FROM password_resets WHERE expires_at <= datetime('now')");

// 5. Delete accounts past grace period
$graceDays = $settings['limits']['account_deletion_grace_days'];
$graceCutoff = date('Y-m-d H:i:s', strtotime("-{$graceDays} days"));
$stmt = $pdo->prepare(
    'SELECT account_id FROM accounts
     WHERE flagged_for_deletion IS NOT NULL
       AND flagged_for_deletion < ?'
);
$stmt->execute([$graceCutoff]);
$deletedAccounts = 0;
while ($row = $stmt->fetch()) {
    // CASCADE handles device_keys, sessions, mailboxes
    $pdo->prepare('DELETE FROM accounts WHERE account_id = ?')
        ->execute([$row['account_id']]);
    $deletedAccounts++;
}

// 6. Recalculate storage_used for all accounts
$pdo->exec(
    "UPDATE accounts SET storage_used = (
        SELECT COALESCE(SUM(b.size_bytes), 0)
        FROM bundles b
        JOIN device_keys dk ON dk.device_public_key = b.recipient_device_key
        WHERE dk.account_id = accounts.account_id AND dk.verified = 1
    )"
);

echo "Cleanup complete: {$expiredCount} expired bundles, {$deletedAccounts} deleted accounts\n";
