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
use Relay\Handler\Invite\FetchInviteHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FetchInviteTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private string $invitesDir;
    private string $accountId;
    private InviteRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $this->invitesDir = sys_get_temp_dir() . '/relay_fetch_' . uniqid();
        $this->settings['storage']['invites_path'] = $this->invitesDir;
        (new AccountRepository($this->pdo))->create('alice@example.com', 'hash', 'uuid');
        $this->accountId = (new AccountRepository($this->pdo))->findByEmail('alice@example.com')['account_id'];
        $this->repo = new InviteRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->invitesDir}/*/*") ?: [] as $f) { unlink($f); }
        foreach (glob("{$this->invitesDir}/*") ?: [] as $d) { if (is_dir($d)) rmdir($d); }
        if (is_dir($this->invitesDir)) { rmdir($this->invitesDir); }
    }

    private function makeHandler(): FetchInviteHandler
    {
        return new FetchInviteHandler($this->repo, new StorageService($this->invitesDir), $this->settings);
    }

    private function seedInvite(string $token, string $expiresAt): void
    {
        $storage  = new StorageService($this->invitesDir);
        $blobPath = $storage->store('inv-' . $token, 'secret-encrypted-data');
        $this->repo->create('inv-' . $token, $this->accountId, $token, $blobPath, 21, $expiresAt);
    }

    public function test_json_accept_returns_base64_payload(): void
    {
        $this->seedInvite('valid-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/valid-tok')
            ->withAttribute('token', 'valid-tok')
            ->withHeader('Accept', 'application/json');
        $response = $this->makeHandler()($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertSame(base64_encode('secret-encrypted-data'), $data['payload']);
        $this->assertArrayHasKey('expires_at', $data);
    }

    public function test_json_fetch_increments_download_count(): void
    {
        $this->seedInvite('count-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/count-tok')
            ->withAttribute('token', 'count-tok')
            ->withHeader('Accept', 'application/json');
        $this->makeHandler()($request);
        $this->assertSame(1, (int) $this->repo->findByToken('count-tok')['download_count']);
    }

    public function test_browser_accept_returns_html_with_invite_url(): void
    {
        $this->seedInvite('html-tok', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/html-tok')
            ->withAttribute('token', 'html-tok')
            ->withHeader('Accept', 'text/html,application/xhtml+xml,*/*');
        $response = $this->makeHandler()($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $this->assertStringContainsString('KrillNotes', $body);
        $this->assertStringContainsString('html-tok', $body);
    }

    public function test_html_fetch_does_not_increment_download_count(): void
    {
        $this->seedInvite('html-count', '2099-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/html-count')
            ->withAttribute('token', 'html-count')
            ->withHeader('Accept', 'text/html,*/*');
        $this->makeHandler()($request);
        $this->assertSame(0, (int) $this->repo->findByToken('html-count')['download_count']);
    }

    public function test_unknown_token_returns_404(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/nope')
            ->withAttribute('token', 'nope')
            ->withHeader('Accept', 'application/json');
        $this->assertSame(404, $this->makeHandler()($request)->getStatusCode());
    }

    public function test_expired_invite_returns_410(): void
    {
        $this->seedInvite('expired-tok', '2000-01-01 00:00:00');
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/invites/expired-tok')
            ->withAttribute('token', 'expired-tok')
            ->withHeader('Accept', 'application/json');
        $response = $this->makeHandler()($request);
        $this->assertSame(410, $response->getStatusCode());
        $this->assertSame('GONE', json_decode((string) $response->getBody(), true)['error']['code']);
    }
}
