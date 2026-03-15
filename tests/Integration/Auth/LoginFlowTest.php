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
use Relay\Handler\Auth\LoginHandler;
use Relay\Handler\Auth\RegisterHandler;
use Relay\Handler\Auth\RegisterVerifyHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\SessionRepository;
use Relay\Service\AuthService;
use Relay\Service\CryptoService;
use Slim\Psr7\Factory\ServerRequestFactory;
final class LoginFlowTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private AccountRepository $accounts;
    private SessionRepository $sessions;
    private AuthService $auth;
    private LoginHandler $loginHandler;
    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $this->accounts = new AccountRepository($this->pdo);
        $this->sessions = new SessionRepository($this->pdo);
        $this->auth = new AuthService();
        $this->loginHandler = new LoginHandler(
            $this->accounts,
            $this->sessions,
            $this->auth,
            $this->settings,
        );
    }

    /**
     * Register an account and complete the PoP flow so device key is verified.
     * Returns ['account_id' => ..., 'session_token' => ...]
     */
    private function registerAccount(string $email, string $password): array
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edSk = sodium_crypto_sign_secretkey($edKp);
        $edPkHex = bin2hex($edPk);

        $registerHandler = new RegisterHandler(
            $this->accounts,
            new DeviceKeyRepository($this->pdo),
            new ChallengeRepository($this->pdo),
            $this->auth,
            new CryptoService(),
            $this->settings,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody([
                'email' => $email,
                'password' => $password,
                'identity_uuid' => 'id-uuid-' . md5($email),
                'device_public_key' => $edPkHex,
            ]);
        $response = $registerHandler($request);
        $data = json_decode((string) $response->getBody(), true)['data'];

        // Decrypt challenge
        $clientX25519Sk = sodium_crypto_sign_ed25519_sk_to_curve25519($edSk);
        $serverX25519Pk = hex2bin($data['challenge']['server_public_key']);
        $blob = hex2bin($data['challenge']['encrypted_nonce']);
        $boxNonce = substr($blob, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $decryptKp = sodium_crypto_box_keypair_from_secretkey_and_publickey($clientX25519Sk, $serverX25519Pk);
        $plaintext = sodium_crypto_box_open($ciphertext, $boxNonce, $decryptKp);

        $verifyHandler = new RegisterVerifyHandler(
            new ChallengeRepository($this->pdo),
            new DeviceKeyRepository($this->pdo),
            $this->sessions,
            new CryptoService(),
            $this->settings,
        );
        $verifyRequest = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register/verify')
            ->withParsedBody(['device_public_key' => $edPkHex, 'nonce' => bin2hex($plaintext)]);
        $verifyResponse = $verifyHandler($verifyRequest);
        return json_decode((string) $verifyResponse->getBody(), true)['data'];
    }

    public function test_login_with_correct_credentials_returns_session_token(): void
    {
        $this->registerAccount('bob@example.com', 'correcthorse');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody(['email' => 'bob@example.com', 'password' => 'correcthorse']);
        $response = ($this->loginHandler)($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertArrayHasKey('session_token', $data);
        $this->assertNotEmpty($data['session_token']);
    }

    public function test_login_with_wrong_password_returns_401(): void
    {
        $this->registerAccount('carol@example.com', 'realpassword');

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody(['email' => 'carol@example.com', 'password' => 'wrongpassword']);
        $response = ($this->loginHandler)($request);

        $this->assertSame(401, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('INVALID_CREDENTIALS', $error['code']);
    }

    public function test_login_with_nonexistent_email_returns_401(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/login')
            ->withParsedBody(['email' => 'nobody@example.com', 'password' => 'somepassword']);
        $response = ($this->loginHandler)($request);

        $this->assertSame(401, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('INVALID_CREDENTIALS', $error['code']);
    }
}
