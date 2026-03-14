# CLI Admin Tool Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a read-only CLI admin tool (`bin/admin.php`) that provides a full dashboard and per-section drill-downs into the relay database and storage.

**Architecture:** Single PHP file following the same bootstrap pattern as `bin/cleanup.php` — require autoloader, load settings, create PDO via `Connection::create()`, run queries directly. Each section is a standalone function with `(PDO $pdo, array $settings, bool $detailed)` signature. A dispatch block routes `$argv[1]` to the appropriate function.

**Tech Stack:** PHP 8.3, SQLite (PDO), PHPUnit for tests. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-03-15-cli-admin-tool-design.md`

---

## Chunk 1: Helper Functions + Dispatch Skeleton

### Task 1: Write failing tests for helper functions

**Files:**
- Create: `tests/Unit/Cli/AdminHelpersTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;

// We'll require the admin.php file and test its functions directly.
// The functions will be defined in the global namespace inside bin/admin.php.

final class AdminHelpersTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Load the admin functions (the file guards against double-include
        // and skips dispatch when included from tests).
        require_once dirname(__DIR__, 3) . '/bin/admin.php';
    }

    // --- humanBytes ---

    public function test_human_bytes_zero(): void
    {
        $this->assertSame('0 B', humanBytes(0));
    }

    public function test_human_bytes_bytes(): void
    {
        $this->assertSame('512 B', humanBytes(512));
    }

    public function test_human_bytes_kilobytes(): void
    {
        $this->assertSame('1.5 KB', humanBytes(1536));
    }

    public function test_human_bytes_megabytes(): void
    {
        $this->assertSame('10.0 MB', humanBytes(10 * 1024 * 1024));
    }

    public function test_human_bytes_gigabytes(): void
    {
        $this->assertSame('2.3 GB', humanBytes(2469606195));
    }

    // --- relativeTime ---

    public function test_relative_time_null(): void
    {
        $this->assertSame('never', relativeTime(null));
    }

    public function test_relative_time_seconds_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-30 seconds'));
        $this->assertSame('just now', relativeTime($ts));
    }

    public function test_relative_time_minutes_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        $this->assertSame('5m ago', relativeTime($ts));
    }

    public function test_relative_time_hours_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-3 hours'));
        $this->assertSame('3h ago', relativeTime($ts));
    }

    public function test_relative_time_days_ago(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-12 days'));
        $this->assertSame('12d ago', relativeTime($ts));
    }

    // --- relativeTimeFuture ---

    public function test_relative_time_future_minutes(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+48 minutes'));
        $this->assertSame('in 48m', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_hours(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+5 hours'));
        $this->assertSame('in 5h', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_days(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('+16 days'));
        $this->assertSame('in 16d', relativeTimeFuture($ts));
    }

    public function test_relative_time_future_already_expired(): void
    {
        $ts = date('Y-m-d H:i:s', strtotime('-2 days'));
        $this->assertSame('expired 2d ago!', relativeTimeFuture($ts));
    }

    // --- progressBar ---

    public function test_progress_bar_normal(): void
    {
        $result = progressBar(54 * 1024 * 1024, 100 * 1024 * 1024);
        $this->assertSame('54.0 MB / 100.0 MB (54%)', $result);
    }

    public function test_progress_bar_zero(): void
    {
        $result = progressBar(0, 100 * 1024 * 1024);
        $this->assertSame('0 B / 100.0 MB (0%)', $result);
    }

    public function test_progress_bar_overflow(): void
    {
        $current = 112 * 1024 * 1024;
        $max = 100 * 1024 * 1024;
        $result = progressBar($current, $max);
        $this->assertSame('112.0 MB / 100.0 MB (112%)', $result);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminHelpersTest.php -v`
Expected: FAIL — file not found / functions not defined.

### Task 2: Create admin.php with helpers and dispatch skeleton

**Files:**
- Create: `bin/admin.php`

- [ ] **Step 3: Write the admin.php file with helpers and dispatch**

```php
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
    echo "Accounts: (not yet implemented)\n";
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
```

- [ ] **Step 4: Add PHPUNIT_RUNNING constant to test bootstrap**

In `tests/bootstrap.php`, add the constant so `admin.php` skips dispatch when loaded in tests:

```php
// Add before the require line:
define('PHPUNIT_RUNNING', true);
```

File: `tests/bootstrap.php` — modify.

- [ ] **Step 5: Run helper tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminHelpersTest.php -v`
Expected: All 14 tests PASS.

- [ ] **Step 6: Run full test suite to confirm no regressions**

Run: `php vendor/bin/phpunit -v`
Expected: All existing tests still PASS.

- [ ] **Step 7: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminHelpersTest.php tests/bootstrap.php
git commit -m "feat: admin CLI skeleton with helpers and dispatch

Includes PHPUNIT_RUNNING guard in bootstrap.php so admin.php
skips DB bootstrap when loaded from tests."
```

---

## Chunk 2: showAccounts

### Task 3: Write failing tests for showAccounts

**Files:**
- Create: `tests/Unit/Cli/AdminAccountsTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\DeviceKeyRepository;

final class AdminAccountsTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_accounts_dashboard_with_no_accounts(): void
    {
        ob_start();
        showAccounts($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Accounts:', $output);
        $this->assertStringContainsString('0 total', $output);
    }

    public function test_accounts_dashboard_with_data(): void
    {
        $repo = new AccountRepository($this->pdo);
        $repo->create('alice@example.com', 'hash', 'uuid1');
        $repo->create('bob@example.com', 'hash', 'uuid2');

        ob_start();
        showAccounts($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('2 total', $output);
    }

    public function test_accounts_detail_shows_emails(): void
    {
        $repo = new AccountRepository($this->pdo);
        $repo->create('alice@example.com', 'hash', 'uuid1');

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@example.com', $output);
    }

    public function test_accounts_detail_shows_device_counts(): void
    {
        $repo = new AccountRepository($this->pdo);
        $id = $repo->create('alice@example.com', 'hash', 'uuid1');

        $dk = new DeviceKeyRepository($this->pdo);
        $dk->add($id, 'pubkey1');
        $dk->add($id, 'pubkey2');
        $dk->markVerified('pubkey1');

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('2 (1 verified)', $output);
    }

    public function test_accounts_detail_shows_flagged(): void
    {
        $repo = new AccountRepository($this->pdo);
        $id = $repo->create('alice@example.com', 'hash', 'uuid1');
        $repo->flagForDeletion($id);

        ob_start();
        showAccounts($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('yes', $output);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminAccountsTest.php -v`
Expected: FAIL — output says "(not yet implemented)".

### Task 4: Implement showAccounts

**Files:**
- Modify: `bin/admin.php` — replace the `showAccounts` stub (before the guard).

- [ ] **Step 3: Replace the showAccounts stub**

```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminAccountsTest.php -v`
Expected: All 5 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminAccountsTest.php
git commit -m "feat: implement showAccounts section in admin CLI"
```

---

## Chunk 3: showBundles

### Task 5: Write failing tests for showBundles

**Files:**
- Create: `tests/Unit/Cli/AdminBundlesTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\MailboxRepository;
use Relay\Repository\BundleRepository;

final class AdminBundlesTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_bundles_dashboard_empty(): void
    {
        ob_start();
        showBundles($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Bundles:', $output);
        $this->assertStringContainsString('0 pending', $output);
    }

    public function test_bundles_dashboard_with_data(): void
    {
        $this->seedBundle('ws-1', 'sender-key', 'recipient-key', 'delta', 1024);

        ob_start();
        showBundles($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 pending', $output);
    }

    public function test_bundles_detail_shows_workspace(): void
    {
        $this->seedBundleWithMailbox(
            'workspace-abc123def456',
            'alice@example.com',
            'delta',
            2048
        );

        ob_start();
        showBundles($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('workspac...', $output);
        $this->assertStringContainsString('alice@example.com', $output);
        $this->assertStringContainsString('1 delta', $output);
    }

    public function test_bundles_detail_unknown_first_member(): void
    {
        // Bundle exists but no mailbox for the workspace
        $this->seedBundle('orphan-ws', 'sender', 'recipient', 'snapshot', 512);

        ob_start();
        showBundles($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('(unknown)', $output);
    }

    private function seedBundle(
        string $workspaceId,
        string $senderKey,
        string $recipientKey,
        string $mode,
        int $size
    ): void {
        $bundleRepo = new BundleRepository($this->pdo);
        $bundleId = bin2hex(random_bytes(16));
        $bundleRepo->createWithId(
            $bundleId,
            $workspaceId,
            $senderKey,
            $recipientKey,
            $mode,
            $size,
            "bundles/ab/{$bundleId}.swarm"
        );
    }

    private function seedBundleWithMailbox(
        string $workspaceId,
        string $email,
        string $mode,
        int $size
    ): void {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create($email, 'hash', 'uuid-' . bin2hex(random_bytes(4)));

        $dk = new DeviceKeyRepository($this->pdo);
        $pubkey = 'dk-' . bin2hex(random_bytes(8));
        $dk->add($accountId, $pubkey);
        $dk->markVerified($pubkey);

        $mailboxRepo = new MailboxRepository($this->pdo);
        $mailboxRepo->create($accountId, $workspaceId);

        $this->seedBundle($workspaceId, $pubkey, $pubkey, $mode, $size);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminBundlesTest.php -v`
Expected: FAIL — output says "(not yet implemented)".

### Task 6: Implement showBundles

**Files:**
- Modify: `bin/admin.php` — replace the `showBundles` stub (before the guard).

- [ ] **Step 3: Replace the showBundles stub**

```php
function showBundles(\PDO $pdo, array $settings, bool $detailed): void
{
    $retentionDays = $settings['limits']['bundle_retention_days'];

    // Summary stats
    $stats = $pdo->query(
        "SELECT
            COUNT(*) as total,
            COALESCE(SUM(size_bytes), 0) as total_size,
            MIN(created_at) as oldest_created
         FROM bundles"
    )->fetch();

    $total = (int) $stats['total'];
    $totalSize = (int) $stats['total_size'];
    $oldestCreated = $stats['oldest_created'];

    $oldestStr = '';
    if ($oldestCreated) {
        $oldestStr = ', oldest: ' . relativeTime($oldestCreated);
        // Compute expiry of oldest bundle
        $expiresAt = date('Y-m-d H:i:s', strtotime($oldestCreated) + $retentionDays * 86400);
        $oldestStr .= ' (' . relativeTimeFuture($expiresAt) . ')';
    }

    echo "Bundles: " . number_format($total) . " pending, "
        . humanBytes($totalSize) . " on disk{$oldestStr}\n";

    if (!$detailed) {
        return;
    }

    // Per-workspace detail with first member lookup
    $rows = $pdo->query(
        "SELECT
            b.workspace_id,
            COUNT(*) as bundle_count,
            SUM(b.size_bytes) as total_size,
            MIN(b.created_at) as oldest,
            MAX(b.created_at) as newest,
            GROUP_CONCAT(b.mode) as modes
         FROM bundles b
         GROUP BY b.workspace_id
         ORDER BY bundle_count DESC"
    )->fetchAll();

    $tableRows = [];
    foreach ($rows as $row) {
        // Find first member
        $member = $pdo->prepare(
            "SELECT a.email
             FROM mailboxes m
             JOIN accounts a ON a.account_id = m.account_id
             WHERE m.workspace_id = ?
             ORDER BY m.registered_at ASC
             LIMIT 1"
        );
        $member->execute([$row['workspace_id']]);
        $firstMember = $member->fetchColumn() ?: '(unknown)';

        // Mode breakdown
        $modeList = explode(',', $row['modes']);
        $modeCounts = array_count_values($modeList);
        $modeStr = implode(', ', array_map(
            fn($count, $mode) => "{$count} {$mode}",
            $modeCounts,
            array_keys($modeCounts)
        ));

        // Truncate workspace ID
        $wsDisplay = strlen($row['workspace_id']) > 11
            ? substr($row['workspace_id'], 0, 8) . '...'
            : $row['workspace_id'];

        $tableRows[] = [
            'workspace'   => $wsDisplay,
            'firstMember' => $firstMember,
            'bundles'     => (string) $row['bundle_count'],
            'size'        => humanBytes((int) $row['total_size']),
            'oldest'      => relativeTime($row['oldest']),
            'newest'      => relativeTime($row['newest']),
            'modes'       => $modeStr,
        ];
    }

    echo "\n";
    printTable(
        ['Workspace', 'First Member', 'Bundles', 'Size', 'Oldest', 'Newest', 'Modes'],
        $tableRows
    );
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminBundlesTest.php -v`
Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminBundlesTest.php
git commit -m "feat: implement showBundles section in admin CLI"
```

---

## Chunk 4: showSessions

### Task 7: Write failing tests for showSessions

**Files:**
- Create: `tests/Unit/Cli/AdminSessionsTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\SessionRepository;
use Relay\Repository\ChallengeRepository;

final class AdminSessionsTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_sessions_dashboard_empty(): void
    {
        ob_start();
        showSessions($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Sessions:', $output);
        $this->assertStringContainsString('0 active', $output);
    }

    public function test_sessions_dashboard_with_data(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $sessionRepo = new SessionRepository($this->pdo);
        $sessionRepo->create($accountId, 86400 * 30);

        ob_start();
        showSessions($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 active', $output);
        $this->assertStringContainsString('1 accounts with active sessions', $output);
    }

    public function test_sessions_detail_shows_account(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $sessionRepo = new SessionRepository($this->pdo);
        $sessionRepo->create($accountId, 86400 * 30);

        ob_start();
        showSessions($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@test.com', $output);
    }

    public function test_sessions_shows_challenge_summary(): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create('alice@test.com', 'hash', 'uuid');

        $challengeRepo = new ChallengeRepository($this->pdo);
        $challengeRepo->create($accountId, 'pubkey', 'nonce', 'serverpub', 'registration', 300);

        ob_start();
        showSessions($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('Challenges:', $output);
        $this->assertStringContainsString('1 pending', $output);
        $this->assertStringContainsString('registration', $output);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminSessionsTest.php -v`
Expected: FAIL — output says "(not yet implemented)".

### Task 8: Implement showSessions

**Files:**
- Modify: `bin/admin.php` — replace the `showSessions` stub (before the guard).

- [ ] **Step 3: Replace the showSessions stub**

```php
function showSessions(\PDO $pdo, array $settings, bool $detailed): void
{
    // Summary stats
    $stats = $pdo->query(
        "SELECT
            COUNT(*) as total,
            MIN(created_at) as oldest,
            MIN(expires_at) as soonest_expiry,
            COUNT(DISTINCT account_id) as account_count
         FROM sessions
         WHERE expires_at > datetime('now')"
    )->fetch();

    $total = (int) $stats['total'];
    $accountCount = (int) $stats['account_count'];

    $oldestStr = '';
    if ($stats['oldest']) {
        $oldestStr = ', oldest: ' . relativeTime($stats['oldest'])
            . ' (' . relativeTimeFuture($stats['soonest_expiry']) . ')';
    }

    echo "Sessions: {$total} active{$oldestStr}, {$accountCount} accounts with active sessions\n";

    if (!$detailed) {
        return;
    }

    // Per-account detail
    $rows = $pdo->query(
        "SELECT
            a.email,
            COUNT(s.token) as session_count,
            MAX(s.created_at) as newest,
            MIN(s.created_at) as oldest,
            MIN(s.expires_at) as soonest_expiry
         FROM sessions s
         JOIN accounts a ON a.account_id = s.account_id
         WHERE s.expires_at > datetime('now')
         GROUP BY s.account_id
         ORDER BY session_count DESC"
    )->fetchAll();

    $tableRows = [];
    foreach ($rows as $row) {
        $tableRows[] = [
            'account'  => $row['email'],
            'sessions' => (string) $row['session_count'],
            'newest'   => relativeTime($row['newest']),
            'oldest'   => relativeTime($row['oldest']),
            'expires'  => relativeTimeFuture($row['soonest_expiry']),
        ];
    }

    echo "\n";
    printTable(['Account', 'Sessions', 'Newest', 'Oldest', 'Expires'], $tableRows);

    // Challenge summary
    $challenges = $pdo->query(
        "SELECT context, COUNT(*) as cnt, MIN(created_at) as oldest
         FROM challenges
         WHERE expires_at > datetime('now')
         GROUP BY context"
    )->fetchAll();

    $totalChallenges = array_sum(array_column($challenges, 'cnt'));
    if ($totalChallenges > 0) {
        $parts = array_map(fn($c) => "{$c['cnt']} {$c['context']}", $challenges);
        $oldest = min(array_column($challenges, 'oldest'));
        echo "\nChallenges: {$totalChallenges} pending (" . implode(', ', $parts)
            . '), oldest: ' . relativeTime($oldest) . "\n";
    } else {
        echo "\nChallenges: 0 pending\n";
    }

    // Password reset summary
    $resets = $pdo->query(
        "SELECT COUNT(*) as cnt, MIN(expires_at) as soonest
         FROM password_resets
         WHERE used = 0 AND expires_at > datetime('now')"
    )->fetch();

    $resetCount = (int) $resets['cnt'];
    if ($resetCount > 0) {
        echo "Password resets: {$resetCount} unused, expires "
            . relativeTimeFuture($resets['soonest']) . "\n";
    } else {
        echo "Password resets: 0 unused\n";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminSessionsTest.php -v`
Expected: All 4 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminSessionsTest.php
git commit -m "feat: implement showSessions section in admin CLI"
```

---

## Chunk 5: showInvites

### Task 9: Write failing tests for showInvites

**Files:**
- Create: `tests/Unit/Cli/AdminInvitesTest.php`

- [ ] **Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;

final class AdminInvitesTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_invites_dashboard_empty(): void
    {
        ob_start();
        showInvites($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Invites:', $output);
        $this->assertStringContainsString('0 active', $output);
    }

    public function test_invites_dashboard_with_data(): void
    {
        $this->seedInvite('alice@test.com', 2048, 5);

        ob_start();
        showInvites($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('1 active', $output);
        $this->assertStringContainsString('5 total downloads', $output);
    }

    public function test_invites_detail_shows_token_and_account(): void
    {
        $this->seedInvite('alice@test.com', 1024, 0);

        ob_start();
        showInvites($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@test.com', $output);
        // Token should be truncated
        $this->assertStringContainsString('...', $output);
    }

    private function seedInvite(string $email, int $size, int $downloads): void
    {
        $accountRepo = new AccountRepository($this->pdo);
        $accountId = $accountRepo->create($email, 'hash', 'uuid-' . bin2hex(random_bytes(4)));

        $inviteRepo = new InviteRepository($this->pdo);
        $inviteId = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
        $inviteRepo->create($inviteId, $token, $accountId, "invites/ab/{$inviteId}.swarm", $size, $expiresAt);

        // Simulate downloads
        for ($i = 0; $i < $downloads; $i++) {
            $inviteRepo->incrementDownloadCount($token);
        }
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminInvitesTest.php -v`
Expected: FAIL — output says "(not yet implemented)".

### Task 10: Implement showInvites

**Files:**
- Modify: `bin/admin.php` — replace the `showInvites` stub (before the guard).

- [ ] **Step 3: Replace the showInvites stub**

```php
function showInvites(\PDO $pdo, array $settings, bool $detailed): void
{
    // Summary stats (only non-expired invites)
    $stats = $pdo->query(
        "SELECT
            COUNT(*) as total,
            COALESCE(SUM(size_bytes), 0) as total_size,
            COALESCE(SUM(download_count), 0) as total_downloads
         FROM invites
         WHERE expires_at > datetime('now')"
    )->fetch();

    $total = (int) $stats['total'];
    $totalSize = (int) $stats['total_size'];
    $totalDownloads = (int) $stats['total_downloads'];

    echo "Invites: {$total} active, " . humanBytes($totalSize)
        . " on disk, {$totalDownloads} total downloads\n";

    if (!$detailed) {
        return;
    }

    // Detail table
    $rows = $pdo->query(
        "SELECT
            i.token,
            a.email,
            i.size_bytes,
            i.download_count,
            i.created_at,
            i.expires_at
         FROM invites i
         JOIN accounts a ON a.account_id = i.account_id
         WHERE i.expires_at > datetime('now')
         ORDER BY i.created_at DESC"
    )->fetchAll();

    $tableRows = [];
    foreach ($rows as $row) {
        $tokenDisplay = strlen($row['token']) > 11
            ? substr($row['token'], 0, 8) . '...'
            : $row['token'];

        $tableRows[] = [
            'token'     => $tokenDisplay,
            'account'   => $row['email'],
            'size'      => humanBytes((int) $row['size_bytes']),
            'downloads' => (string) $row['download_count'],
            'created'   => relativeTime($row['created_at']),
            'expires'   => relativeTimeFuture($row['expires_at']),
        ];
    }

    echo "\n";
    printTable(['Token', 'Account', 'Size', 'Downloads', 'Created', 'Expires'], $tableRows);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminInvitesTest.php -v`
Expected: All 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminInvitesTest.php
git commit -m "feat: implement showInvites section in admin CLI"
```

---

## Chunk 6: showHealth

### Task 11: Write failing tests for showHealth

**Files:**
- Create: `tests/Unit/Cli/AdminHealthTest.php`

- [ ] **Step 1: Write the test file**

Note: Health checks filesystem and pragmas. For `:memory:` DBs some checks
differ (no file size, journal mode returns `memory`). We test what we can and
accept that the pragma values in `:memory:` differ from on-disk SQLite.

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;

final class AdminHealthTest extends TestCase
{
    private PDO $pdo;
    private array $settings;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();

        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }

    public function test_health_dashboard(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('Health:', $output);
        $this->assertStringContainsString('migrations applied', $output);
    }

    public function test_health_detail_shows_settings(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== SETTINGS ===', $output);
        $this->assertStringContainsString('Bundle retention:', $output);
        $this->assertStringContainsString('30 days', $output);
        $this->assertStringContainsString('Max storage/account:', $output);
        $this->assertStringContainsString('100.0 MB', $output);
    }

    public function test_health_detail_shows_migrations(): void
    {
        ob_start();
        showHealth($this->pdo, $this->settings, true);
        $output = ob_get_clean();

        $this->assertStringContainsString('=== DATABASE ===', $output);
        $this->assertStringContainsString('Migrations:', $output);
        // All 8 migrations should be applied
        $this->assertStringContainsString('8/8 applied', $output);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminHealthTest.php -v`
Expected: FAIL — output says "(not yet implemented)".

### Task 12: Implement showHealth

**Files:**
- Modify: `bin/admin.php` — replace the `showHealth` stub (before the guard).
  Also add `dirSize()` and `dirFileCount()` helper functions next to it.

- [ ] **Step 3: Replace the showHealth stub and add dir helpers**

```php
function showHealth(\PDO $pdo, array $settings, bool $detailed): void
{
    $migrationsPath = dirname(__DIR__) . '/migrations';
    $dbPath = $settings['database']['path'];
    $bundlesPath = $settings['storage']['bundles_path'];
    $invitesPath = $settings['storage']['invites_path'];

    // Migration count
    $migrationFiles = glob($migrationsPath . '/*.sql');
    $totalMigrations = count($migrationFiles);
    $appliedMigrations = (int) $pdo->query('SELECT COUNT(*) FROM _migrations')->fetchColumn();

    $migrationStr = "{$appliedMigrations}/{$totalMigrations} applied";
    if ($appliedMigrations < $totalMigrations) {
        $pending = $totalMigrations - $appliedMigrations;
        $migrationStr .= " ⚠ {$pending} pending";
    }

    // Pragma checks
    $journalMode = $pdo->query('PRAGMA journal_mode')->fetchColumn();
    $foreignKeys = $pdo->query('PRAGMA foreign_keys')->fetchColumn();

    // File sizes
    $dbSize = file_exists($dbPath) ? filesize($dbPath) : 0;
    $bundlesDiskSize = dirSize($bundlesPath);
    $bundlesFileCount = dirFileCount($bundlesPath);
    $invitesDiskSize = dirSize($invitesPath);
    $invitesFileCount = dirFileCount($invitesPath);

    // Dashboard line
    $journalOk = strtolower($journalMode) === 'wal' ? 'WAL ok' : "journal: {$journalMode}";
    echo "Health: DB " . humanBytes($dbSize) . ", blobs " . humanBytes($bundlesDiskSize)
        . ", invites " . humanBytes($invitesDiskSize)
        . ", {$migrationStr}, {$journalOk}\n";

    if (!$detailed) {
        return;
    }

    // Detail view
    $journalCheck = strtolower($journalMode) === 'wal' ? 'WAL ✓' : "{$journalMode} ⚠";
    $fkCheck = $foreignKeys ? 'ON ✓' : 'OFF ⚠';

    echo "\n=== DATABASE ===\n";
    echo "  File:          " . (file_exists($dbPath) ? "{$dbPath} (" . humanBytes($dbSize) . ")" : '(in-memory)') . "\n";
    echo "  Journal:       {$journalCheck}\n";
    echo "  Foreign keys:  {$fkCheck}\n";
    echo "  Migrations:    {$migrationStr}\n";

    echo "\n=== STORAGE ===\n";
    echo "  Bundles dir:   " . humanBytes($bundlesDiskSize) . " ({$bundlesFileCount} files)\n";
    echo "  Invites dir:   " . humanBytes($invitesDiskSize) . " ({$invitesFileCount} files)\n";
    echo "  Total disk:    " . humanBytes($bundlesDiskSize + $invitesDiskSize) . "\n";

    echo "\n=== SETTINGS ===\n";
    echo "  Bundle retention:     {$settings['limits']['bundle_retention_days']} days\n";
    echo "  Session lifetime:     " . intdiv($settings['auth']['session_lifetime_seconds'], 86400) . " days\n";
    echo "  Challenge lifetime:   " . intdiv($settings['auth']['challenge_lifetime_seconds'], 60) . " minutes\n";
    echo "  Reset token lifetime: " . intdiv($settings['auth']['reset_token_lifetime_seconds'], 3600) . " hour(s)\n";
    echo "  Max bundle size:      " . humanBytes($settings['limits']['max_bundle_size_bytes']) . "\n";
    echo "  Max storage/account:  " . humanBytes($settings['limits']['max_storage_per_account_bytes']) . "\n";
    echo "  Deletion grace:       {$settings['limits']['account_deletion_grace_days']} days\n";
    echo "  Min poll interval:    {$settings['limits']['min_poll_interval_seconds']} seconds\n";
}

function dirSize(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $size = 0;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

function dirFileCount(string $path): int
{
    if (!is_dir($path)) {
        return 0;
    }
    $count = 0;
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $count++;
        }
    }
    return $count;
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php vendor/bin/phpunit tests/Unit/Cli/AdminHealthTest.php -v`
Expected: All 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminHealthTest.php
git commit -m "feat: implement showHealth section in admin CLI"
```

---

## Chunk 7: Final Integration + Docs

### Task 13: Run full test suite

- [ ] **Step 1: Run all tests**

Run: `php vendor/bin/phpunit -v`
Expected: All tests PASS (existing + new admin tests).

### Task 14: Manual smoke test

- [ ] **Step 2: Run admin dashboard against live DB**

Run: `php bin/admin.php`
Expected: Prints all 5 section summary lines. Verify formatting looks right.

- [ ] **Step 3: Run each subcommand**

Run each of:
```bash
php bin/admin.php accounts
php bin/admin.php bundles
php bin/admin.php sessions
php bin/admin.php invites
php bin/admin.php health
php bin/admin.php help
php bin/admin.php nonsense
```

Expected:
- Each prints its summary + detail table.
- `help` prints usage text.
- `nonsense` prints error + usage text and exits with code 1.

### Task 15: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 4: Add admin CLI to key commands section**

In the `## Key commands` section, add:

```bash
# Admin dashboard (read-only DB inspection)
php bin/admin.php              # Full dashboard
php bin/admin.php accounts     # Account details
php bin/admin.php bundles      # Bundle/workspace details
php bin/admin.php sessions     # Session/challenge/reset details
php bin/admin.php invites      # Invite details
php bin/admin.php health       # System health + settings
```

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add admin CLI to CLAUDE.md key commands"
```

### Task 16: Final full test suite run

- [ ] **Step 6: Run all tests one final time**

Run: `php vendor/bin/phpunit -v`
Expected: All tests PASS.
