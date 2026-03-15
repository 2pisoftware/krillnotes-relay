<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

/**
 * Full relay reset — deletes ALL data from the database and storage.
 *
 * This is a destructive, irreversible operation. It removes all accounts,
 * bundles, invites, sessions, and associated blob files. The database
 * schema (migrations) is preserved.
 *
 * Usage:
 *   php bin/reset.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Relay\Database\Connection;

// ── Helpers ─────────────────────────────────────────────────────────

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

function countFiles(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $count = 0;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }
    return $count;
}

function dirSize(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    ) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * Recursively delete all files and subdirectories inside $path,
 * but keep $path itself.
 */
function purgeDirectory(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $deleted = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
            $deleted++;
        }
    }
    return $deleted;
}

// ── Guard: not for tests ────────────────────────────────────────────

if (defined('PHPUNIT_RUNNING')) {
    return;
}

// ── Bootstrap ───────────────────────────────────────────────────────

$settings = require __DIR__ . '/../config/settings.php';
$pdo = Connection::create($settings['database']['path']);

$bundlesPath = $settings['storage']['bundles_path'];
$invitesPath = $settings['storage']['invites_path'];

// ── Gather stats ────────────────────────────────────────────────────

$accounts    = (int) $pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$deviceKeys  = (int) $pdo->query('SELECT COUNT(*) FROM device_keys')->fetchColumn();
$sessions    = (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
$challenges  = (int) $pdo->query('SELECT COUNT(*) FROM challenges')->fetchColumn();
$mailboxes   = (int) $pdo->query('SELECT COUNT(*) FROM mailboxes')->fetchColumn();
$bundles     = (int) $pdo->query('SELECT COUNT(*) FROM bundles')->fetchColumn();
$resets      = (int) $pdo->query('SELECT COUNT(*) FROM password_resets')->fetchColumn();
$invites     = (int) $pdo->query('SELECT COUNT(*) FROM invites')->fetchColumn();

$bundleFiles = countFiles($bundlesPath);
$inviteFiles = countFiles($invitesPath);
$bundleSize  = dirSize($bundlesPath);
$inviteSize  = dirSize($invitesPath);

// ── Show what will be destroyed ─────────────────────────────────────

echo "\n";
echo "  ╔══════════════════════════════════════════════╗\n";
echo "  ║          FULL RELAY RESET                    ║\n";
echo "  ║  This will DELETE all data irreversibly.     ║\n";
echo "  ╚══════════════════════════════════════════════╝\n";
echo "\n";
echo "  Database rows to delete:\n";
echo "    accounts ......... {$accounts}\n";
echo "    device_keys ...... {$deviceKeys}\n";
echo "    sessions ......... {$sessions}\n";
echo "    challenges ....... {$challenges}\n";
echo "    mailboxes ........ {$mailboxes}\n";
echo "    bundles .......... {$bundles}\n";
echo "    password_resets .. {$resets}\n";
echo "    invites .......... {$invites}\n";
echo "\n";
echo "  Storage files to delete:\n";
echo "    bundles/ ......... {$bundleFiles} files (" . humanBytes($bundleSize) . ")\n";
echo "    invites/ ......... {$inviteFiles} files (" . humanBytes($inviteSize) . ")\n";
echo "\n";

$total = $accounts + $deviceKeys + $sessions + $challenges + $mailboxes + $bundles + $resets + $invites;
$totalFiles = $bundleFiles + $inviteFiles;

if ($total === 0 && $totalFiles === 0) {
    echo "  Nothing to reset — the relay is already empty.\n\n";
    exit(0);
}

// ── Confirmation ────────────────────────────────────────────────────

echo "  Type RESET to confirm, or anything else to abort: ";
$input = trim(fgets(STDIN));

if ($input !== 'RESET') {
    echo "\n  Aborted.\n\n";
    exit(1);
}

echo "\n";

// ── Phase 1: Delete storage files ───────────────────────────────────

echo "  [1/2] Purging storage files...\n";
$deletedBundles = purgeDirectory($bundlesPath);
$deletedInvites = purgeDirectory($invitesPath);
echo "        Deleted {$deletedBundles} bundle files, {$deletedInvites} invite files.\n";

// ── Phase 2: Truncate database tables (leaf-to-root FK order) ───────

echo "  [2/2] Clearing database tables...\n";

// Disable FK checks so we can delete in any order without issues
$pdo->exec('PRAGMA foreign_keys=OFF');

$tables = [
    'password_resets',
    'challenges',
    'sessions',
    'bundles',
    'invites',
    'mailboxes',
    'device_keys',
    'accounts',
];

foreach ($tables as $table) {
    $pdo->exec("DELETE FROM {$table}");
}

// Re-enable FK checks
$pdo->exec('PRAGMA foreign_keys=ON');

// Reclaim disk space
$pdo->exec('VACUUM');

echo "        All tables cleared.\n";

// ── Done ────────────────────────────────────────────────────────────

echo "\n  Reset complete. The relay is empty.\n";
echo "  Schema and migrations are intact — no reinstall needed.\n\n";
