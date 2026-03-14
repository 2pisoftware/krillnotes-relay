# Admin CLI: reset-password Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `reset-password <email> <new_password>` subcommand to `bin/admin.php` that directly sets a new bcrypt/argon2id password hash for the given account.

**Architecture:** A single new `resetPassword(\PDO $pdo, string $email, string $newPassword): void` function is added to `bin/admin.php` following the existing pattern. It looks up the account by email, hashes the password, and updates the `accounts` table directly via PDO. Wired into the existing `switch` dispatch and `showHelp()`.

**Tech Stack:** PHP 8.3, PDO/SQLite, PHPUnit, `password_hash()` with ARGON2ID/BCRYPT fallback.

---

## Chunk 1: resetPassword() function, tests, and dispatch wiring

### Task 1: Write the failing test

**Files:**
- Create: `tests/Unit/Cli/AdminResetPasswordTest.php`

- [ ] **Step 1: Create the test file**

```php
<?php

declare(strict_types=1);

namespace Relay\Tests\Unit\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Repository\AccountRepository;

final class AdminResetPasswordTest extends TestCase
{
    private PDO $pdo;
    private AccountRepository $accounts;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/bin/admin.php';

        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->accounts = new AccountRepository($this->pdo);
    }

    public function test_reset_password_updates_hash(): void
    {
        $this->accounts->create('alice@example.com', 'oldhash', 'uuid1');

        resetPassword($this->pdo, 'alice@example.com', 'newpassword123');

        $row = $this->pdo->query(
            "SELECT password_hash FROM accounts WHERE email = 'alice@example.com'"
        )->fetch();

        $this->assertNotEquals('oldhash', $row['password_hash']);
        $this->assertTrue(password_verify('newpassword123', $row['password_hash']));
    }

    public function test_reset_password_unknown_email_exits(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No account found');

        resetPassword($this->pdo, 'nobody@example.com', 'password');
    }

    public function test_reset_password_outputs_confirmation(): void
    {
        $this->accounts->create('alice@example.com', 'oldhash', 'uuid1');

        ob_start();
        resetPassword($this->pdo, 'alice@example.com', 'newpassword123');
        $output = ob_get_clean();

        $this->assertStringContainsString('alice@example.com', $output);
        $this->assertStringContainsString('updated', $output);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Unit/Cli/AdminResetPasswordTest.php --no-coverage
```

Expected: FAIL — `Call to undefined function resetPassword()`

---

### Task 2: Implement resetPassword()

**Files:**
- Modify: `bin/admin.php` — add function before the `PHPUNIT_RUNNING` guard (~line 525)

- [ ] **Step 3: Add the function to bin/admin.php**

Insert this function immediately before the `showHelp()` function (around line 526):

```php
function resetPassword(\PDO $pdo, string $email, string $newPassword): void
{
    $stmt = $pdo->prepare('SELECT account_id FROM accounts WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if ($row === false) {
        throw new \RuntimeException("No account found for email: {$email}");
    }

    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    $hash = password_hash($newPassword, $algo);

    $stmt = $pdo->prepare('UPDATE accounts SET password_hash = ? WHERE account_id = ?');
    $stmt->execute([$hash, $row['account_id']]);

    echo "Password updated for {$email}\n";
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit tests/Unit/Cli/AdminResetPasswordTest.php --no-coverage
```

Expected: 3 tests, 3 assertions, OK

---

### Task 3: Wire into dispatch and help

**Files:**
- Modify: `bin/admin.php` — `switch` block and `showHelp()`

- [ ] **Step 5: Add the dispatch case**

In the `switch ($command)` block (around line 560), add before the `default:` case:

```php
    case 'reset-password':
        $email = $argv[2] ?? '';
        $newPassword = $argv[3] ?? '';
        if (!$email || !$newPassword) {
            echo "Usage: php bin/admin.php reset-password <email> <new_password>\n";
            exit(1);
        }
        try {
            resetPassword($pdo, $email, $newPassword);
        } catch (\RuntimeException $e) {
            echo $e->getMessage() . "\n";
            exit(1);
        }
        break;
```

- [ ] **Step 6: Add to showHelp()**

In `showHelp()` (around line 534), add `reset-password` to the commands list:

```
      reset-password  Reset a user's password (reset-password <email> <new_password>)
```

> Note: align the `#` comment column in CLAUDE.md to match the existing entries in the Key commands block.

- [ ] **Step 7: Run the full test suite to confirm nothing is broken**

```bash
/opt/homebrew/opt/php@8.3/bin/php vendor/bin/phpunit --no-coverage
```

Expected: all tests pass

- [ ] **Step 8: Commit**

```bash
git add bin/admin.php tests/Unit/Cli/AdminResetPasswordTest.php
git commit -m "feat: add reset-password command to admin CLI"
```

---

## Chunk 2: Documentation updates

### Task 4: Update CLAUDE.md

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 9: Add reset-password to the Key commands section**

In the Key commands block, add after `php bin/admin.php health`:

```
php bin/admin.php reset-password <email> <new_password>  # Directly set a user's password
```

- [ ] **Step 10: Fix the stale migration count**

Change `001–007 SQLite .sql files` → `001–008 SQLite .sql files` in the Project layout section.

- [ ] **Step 11: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document reset-password admin command and fix migration count"
```
