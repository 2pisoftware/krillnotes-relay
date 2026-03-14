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
