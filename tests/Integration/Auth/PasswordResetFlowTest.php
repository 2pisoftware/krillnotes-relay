<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Auth\ResetPasswordConfirmHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\PasswordResetRepository;
use Relay\Repository\SessionRepository;
use Relay\Service\AuthService;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PasswordResetFlowTest extends TestCase
{
    private \PDO $pdo;
    private AccountRepository $accounts;
    private SessionRepository $sessions;
    private PasswordResetRepository $resets;
    private AuthService $auth;
    private ResetPasswordConfirmHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $settings = require dirname(__DIR__, 3) . '/config/settings.php';

        $this->accounts = new AccountRepository($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);
        $this->resets = new PasswordResetRepository($this->pdo);
        $this->auth = new AuthService();

        $this->handler = new ResetPasswordConfirmHandler(
            $this->resets,
            $this->accounts,
            $this->sessions,
            $this->auth,
        );
    }

    public function test_password_reset_invalidates_all_sessions(): void
    {
        $accountId = $this->accounts->create(
            'alice@example.com',
            $this->auth->hashPassword('old-password'),
            'identity-uuid-1',
        );

        $session1 = $this->sessions->create($accountId, 3600);
        $session2 = $this->sessions->create($accountId, 3600);
        $this->assertNotNull($this->sessions->findValid($session1));
        $this->assertNotNull($this->sessions->findValid($session2));

        $resetToken = $this->resets->create($accountId, 3600);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/reset-password/confirm')
            ->withParsedBody(['token' => $resetToken, 'new_password' => 'new-password']);
        $response = ($this->handler)($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->sessions->findValid($session1));
        $this->assertNull($this->sessions->findValid($session2));
    }

    public function test_password_reset_does_not_affect_other_accounts_sessions(): void
    {
        $aliceId = $this->accounts->create(
            'alice@example.com',
            $this->auth->hashPassword('alice-pass'),
            'identity-uuid-1',
        );
        $bobId = $this->accounts->create(
            'bob@example.com',
            $this->auth->hashPassword('bob-pass'),
            'identity-uuid-2',
        );

        $bobSession = $this->sessions->create($bobId, 3600);
        $resetToken = $this->resets->create($aliceId, 3600);

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/reset-password/confirm')
            ->withParsedBody(['token' => $resetToken, 'new_password' => 'new-alice-pass']);
        ($this->handler)($request);

        $this->assertNotNull($this->sessions->findValid($bobSession));
    }
}
