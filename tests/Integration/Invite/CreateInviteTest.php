<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Tests\Integration\Invite;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Invite\CreateInviteHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CreateInviteTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private string $invitesDir;
    private string $accountId;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $this->invitesDir = sys_get_temp_dir() . '/relay_test_invites_' . uniqid();
        $this->settings['storage']['invites_path'] = $this->invitesDir;
        (new AccountRepository($this->pdo))->create('alice@example.com', password_hash('pw', PASSWORD_BCRYPT), 'uuid-alice');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->invitesDir}/*/*") ?: [] as $f) { unlink($f); }
        foreach (glob("{$this->invitesDir}/*") ?: [] as $d) { if (is_dir($d)) rmdir($d); }
        if (is_dir($this->invitesDir)) { rmdir($this->invitesDir); }
    }

    private function makeHandler(): CreateInviteHandler
    {
        return new CreateInviteHandler(
            new InviteRepository($this->pdo),
            new StorageService($this->invitesDir),
            $this->settings,
        );
    }

    public function test_create_invite_returns_201_with_token_and_url(): void
    {
        $expiresAt = date('Y-m-d\TH:i:s\Z', strtotime('+7 days'));
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('encrypted-data'), 'expires_at' => $expiresAt]);
        $response = $this->makeHandler()($request);
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertNotEmpty($data['invite_id']);
        $this->assertSame(64, strlen($data['token']));
        $this->assertStringContainsString('/invites/', $data['url']);
        $this->assertSame($expiresAt, $data['expires_at']);
    }

    public function test_missing_payload_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('MISSING_FIELDS', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_invalid_base64_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => '!!!not-base64!!!', 'expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+1 day'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_PAYLOAD', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_expires_at_in_past_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('data'), 'expires_at' => '2000-01-01T00:00:00Z']);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_EXPIRY', json_decode((string) $response->getBody(), true)['error']['code']);
    }

    public function test_expires_at_beyond_90_days_returns_400(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/invites')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['payload' => base64_encode('data'), 'expires_at' => date('Y-m-d\TH:i:s\Z', strtotime('+91 days'))]);
        $response = $this->makeHandler()($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('INVALID_EXPIRY', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
