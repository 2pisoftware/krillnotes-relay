<?php

/**
 * CLI admin tool for inspecting the relay database and storage.
 *
 * Usage:
 *   php bin/admin.php              Full dashboard
 *   php bin/admin.php accounts     Account details
 *   php bin/admin.php bundles      Bundle/workspace details
 *   php bin/admin.php sessions     Session, challenge, and reset details
 *   php bin/admin.php invites      Invite details
 *   php bin/admin.php health       System health
 *   php bin/admin.php help         Show this help
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Relay\Database\Connection;

// ── Helper functions ────────────────────────────────────────────────

function humanBytes(int $bytes): string
{
    if ($bytes === 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $value = (float) $bytes;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return $i === 0
        ? "{$bytes} B"
        : sprintf('%.1f %s', $value, $units[$i]);
}

function relativeTime(?string $timestamp): string
{
    if ($timestamp === null) {
        return 'never';
    }
    $diff = time() - strtotime($timestamp);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . 'm ago';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . 'h ago';
    }
    return intdiv($diff, 86400) . 'd ago';
}

function relativeTimeFuture(string $timestamp): string
{
    $diff = strtotime($timestamp) - time();
    if ($diff < 0) {
        $ago = abs($diff);
        if ($ago < 3600) {
            return 'expired ' . intdiv($ago, 60) . 'm ago!';
        }
        if ($ago < 86400) {
            return 'expired ' . intdiv($ago, 3600) . 'h ago!';
        }
        return 'expired ' . intdiv($ago, 86400) . 'd ago!';
    }
    if ($diff < 3600) {
        return 'in ' . intdiv($diff, 60) . 'm';
    }
    if ($diff < 86400) {
        return 'in ' . intdiv($diff, 3600) . 'h';
    }
    return 'in ' . intdiv($diff, 86400) . 'd';
}

function progressBar(int $current, int $max): string
{
    $pct = $max > 0 ? (int) round($current / $max * 100) : 0;
    return humanBytes($current) . ' / ' . humanBytes($max) . " ({$pct}%)";
}

// ── Table formatter ─────────────────────────────────────────────────

function printTable(array $headers, array $rows): void
{
    if (empty($rows)) {
        echo "  (none)\n";
        return;
    }

    // Calculate column widths
    $widths = [];
    foreach ($headers as $i => $header) {
        $widths[$i] = mb_strlen($header);
    }
    foreach ($rows as $row) {
        foreach (array_values($row) as $i => $cell) {
            $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
        }
    }

    // Print header
    $line = '  ';
    foreach ($headers as $i => $header) {
        $line .= str_pad($header, $widths[$i] + 2);
    }
    echo $line . "\n";
    echo '  ' . str_repeat('─', array_sum($widths) + count($widths) * 2) . "\n";

    // Print rows
    foreach ($rows as $row) {
        $line = '  ';
        foreach (array_values($row) as $i => $cell) {
            $line .= str_pad((string) $cell, $widths[$i] + 2);
        }
        echo $line . "\n";
    }
}

// ── Section stubs (to be implemented in subsequent tasks) ───────────
// All functions MUST be defined before the PHPUNIT_RUNNING guard so
// they are available when the file is loaded from tests.

function showAccounts(\PDO $pdo, array $settings, bool $detailed): void
{
    $maxStorage = $settings['limits']['max_storage_per_account_bytes'];

    // Summary stats
    $stats = $pdo->query(
        "SELECT
            COUNT(*) as total,
            SUM(CASE WHEN flagged_for_deletion IS NOT NULL THEN 1 ELSE 0 END) as flagged,
            SUM(storage_used) as total_storage
         FROM accounts"
    )->fetch();

    $total = (int) $stats['total'];
    $flagged = (int) $stats['flagged'];
    $totalStorage = (int) $stats['total_storage'];

    echo "Accounts: {$total} total, {$flagged} flagged for deletion, "
        . humanBytes($totalStorage) . " total storage used\n";

    if (!$detailed) {
        return;
    }

    // Detail table
    $rows = $pdo->query(
        "SELECT
            a.email,
            a.storage_used,
            a.flagged_for_deletion,
            a.created_at,
            a.last_poll_at,
            COUNT(dk.id) as device_total,
            SUM(CASE WHEN dk.verified = 1 THEN 1 ELSE 0 END) as device_verified
         FROM accounts a
         LEFT JOIN device_keys dk ON dk.account_id = a.account_id
         GROUP BY a.account_id
         ORDER BY a.created_at DESC"
    )->fetchAll();

    $tableRows = [];
    foreach ($rows as $row) {
        $flaggedStr = $row['flagged_for_deletion']
            ? 'yes (' . relativeTime($row['flagged_for_deletion']) . ')'
            : '—';

        $tableRows[] = [
            'email'    => $row['email'],
            'devices'  => $row['device_total'] . ' (' . (int) $row['device_verified'] . ' verified)',
            'storage'  => progressBar((int) $row['storage_used'], $maxStorage),
            'flagged'  => $flaggedStr,
            'created'  => relativeTime($row['created_at']),
            'lastPoll' => relativeTime($row['last_poll_at']),
        ];
    }

    echo "\n";
    printTable(['Email', 'Devices', 'Storage', 'Flagged', 'Created', 'Last Poll'], $tableRows);
}

function showBundles(\PDO $pdo, array $settings, bool $detailed): void
{
    echo "Bundles: (not yet implemented)\n";
}

function showSessions(\PDO $pdo, array $settings, bool $detailed): void
{
    echo "Sessions: (not yet implemented)\n";
}

function showInvites(\PDO $pdo, array $settings, bool $detailed): void
{
    echo "Invites: (not yet implemented)\n";
}

function showHealth(\PDO $pdo, array $settings, bool $detailed): void
{
    echo "Health: (not yet implemented)\n";
}

function showHelp(): void
{
    echo <<<HELP
    swarm-relay admin tool

    Usage: php bin/admin.php [command]

    Commands:
      (none)     Full dashboard (all sections)
      accounts   Account details
      bundles    Bundle/workspace details
      sessions   Session, challenge, and reset details
      invites    Invite details
      health     System health — DB, storage, migrations, settings
      help       Show this help

    HELP;
}

// ── Guard: skip dispatch when loaded from tests ─────────────────────

if (defined('PHPUNIT_RUNNING')) {
    return;
}

// ── Bootstrap ───────────────────────────────────────────────────────

$settings = require __DIR__ . '/../config/settings.php';
$pdo = Connection::create($settings['database']['path']);

// ── Dispatch ────────────────────────────────────────────────────────

$command = $argv[1] ?? 'dashboard';

switch ($command) {
    case 'dashboard':
        showAccounts($pdo, $settings, false);
        showBundles($pdo, $settings, false);
        showSessions($pdo, $settings, false);
        showInvites($pdo, $settings, false);
        showHealth($pdo, $settings, false);
        break;
    case 'accounts':
        showAccounts($pdo, $settings, true);
        break;
    case 'bundles':
        showBundles($pdo, $settings, true);
        break;
    case 'sessions':
        showSessions($pdo, $settings, true);
        break;
    case 'invites':
        showInvites($pdo, $settings, true);
        break;
    case 'health':
        showHealth($pdo, $settings, true);
        break;
    case 'help':
        showHelp();
        break;
    default:
        echo "Unknown command: {$command}\n\n";
        showHelp();
        exit(1);
}
