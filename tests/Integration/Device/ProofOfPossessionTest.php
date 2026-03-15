<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Tests\Integration\Device;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Account\AddDeviceHandler;
use Relay\Handler\Account\RemoveDeviceHandler;
use Relay\Handler\Account\VerifyDeviceHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\CryptoService;
use Slim\Psr7\Factory\ServerRequestFactory;
final class ProofOfPossessionTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    private string $accountId;
    private DeviceKeyRepository $deviceKeys;
    private ChallengeRepository $challenges;
    private CryptoService $crypto;

    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';

        $this->deviceKeys = new DeviceKeyRepository($this->pdo);
        $this->challenges = new ChallengeRepository($this->pdo);
        $this->crypto = new CryptoService();

        // Create a base account to authenticate against
        $accounts = new AccountRepository($this->pdo);
        $this->accountId = $accounts->create(
            'test@example.com',
            password_hash('password', PASSWORD_BCRYPT),
            'identity-uuid-test'
        );
    }

    public function test_add_device_returns_201_and_challenge(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        $handler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex]);

        $response = $handler($request);

        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertArrayHasKey('challenge', $data);
        $this->assertArrayHasKey('encrypted_nonce', $data['challenge']);
        $this->assertArrayHasKey('server_public_key', $data['challenge']);
    }

    public function test_add_device_missing_key_returns_400(): void
    {
        $handler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody([]);

        $response = $handler($request);
        $this->assertSame(400, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('MISSING_FIELDS', $error['code']);
    }

    public function test_verify_device_with_correct_nonce_returns_200_and_marks_verified(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edSk = sodium_crypto_sign_secretkey($edKp);
        $edPkHex = bin2hex($edPk);

        // Step 1: Add device
        $addHandler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );
        $addRequest = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex]);
        $addResponse = $addHandler($addRequest);
        $this->assertSame(201, $addResponse->getStatusCode());

        $challengeData = json_decode((string) $addResponse->getBody(), true)['data']['challenge'];
        $encryptedNonce = $challengeData['encrypted_nonce'];
        $serverPkHex = $challengeData['server_public_key'];

        // Step 2: Client decrypts challenge
        $clientX25519Sk = sodium_crypto_sign_ed25519_sk_to_curve25519($edSk);
        $serverX25519Pk = hex2bin($serverPkHex);
        $blob = hex2bin($encryptedNonce);
        $boxNonce = substr($blob, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $decryptKp = sodium_crypto_box_keypair_from_secretkey_and_publickey($clientX25519Sk, $serverX25519Pk);
        $plaintext = sodium_crypto_box_open($ciphertext, $boxNonce, $decryptKp);
        $this->assertNotFalse($plaintext, 'Client must be able to decrypt the challenge');

        // Step 3: Verify with correct nonce
        $verifyHandler = new VerifyDeviceHandler(
            $this->challenges,
            $this->deviceKeys,
            $this->crypto,
        );
        $verifyRequest = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices/verify')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex, 'nonce' => bin2hex($plaintext)]);
        $verifyResponse = $verifyHandler($verifyRequest);

        $this->assertSame(200, $verifyResponse->getStatusCode());
        $verifyData = json_decode((string) $verifyResponse->getBody(), true)['data'];
        $this->assertTrue($verifyData['ok']);

        // Device key should now be verified in DB
        $found = $this->deviceKeys->findAccountByKey($edPkHex);
        $this->assertNotNull($found, 'Device key should be marked verified');
    }

    public function test_verify_device_with_wrong_nonce_returns_403(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        // Add device to create a pending challenge
        $addHandler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );
        $addRequest = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex]);
        $addHandler($addRequest);

        // Verify with wrong nonce
        $verifyHandler = new VerifyDeviceHandler(
            $this->challenges,
            $this->deviceKeys,
            $this->crypto,
        );
        $wrongNonce = bin2hex(random_bytes(32));
        $verifyRequest = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices/verify')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex, 'nonce' => $wrongNonce]);
        $verifyResponse = $verifyHandler($verifyRequest);

        $this->assertSame(403, $verifyResponse->getStatusCode());
        $error = json_decode((string) $verifyResponse->getBody(), true)['error'];
        $this->assertSame('INVALID_NONCE', $error['code']);
    }

    public function test_remove_device_returns_200(): void
    {
        // Add and verify a device first
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        $this->deviceKeys->add($this->accountId, $edPkHex);
        $this->deviceKeys->markVerified($edPkHex);

        $removeHandler = new RemoveDeviceHandler($this->deviceKeys);
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/account/devices/' . $edPkHex)
            ->withAttribute('account_id', $this->accountId)
            ->withAttribute('device_key', $edPkHex);

        $response = $removeHandler($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $this->assertTrue($data['ok']);

        // Should be gone from DB
        $keys = $this->deviceKeys->listForAccount($this->accountId);
        $this->assertCount(0, $keys);
    }

    public function test_remove_nonexistent_device_returns_404(): void
    {
        $removeHandler = new RemoveDeviceHandler($this->deviceKeys);
        $fakePkHex = bin2hex(random_bytes(32));
        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/account/devices/' . $fakePkHex)
            ->withAttribute('account_id', $this->accountId)
            ->withAttribute('device_key', $fakePkHex);

        $response = $removeHandler($request);

        $this->assertSame(404, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('NOT_FOUND', $error['code']);
    }

    public function test_add_duplicate_verified_key_returns_409(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        // Add and verify the key
        $this->deviceKeys->add($this->accountId, $edPkHex);
        $this->deviceKeys->markVerified($edPkHex);

        $handler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex]);

        $response = $handler($request);

        $this->assertSame(409, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('KEY_EXISTS', $error['code']);
    }

    public function test_add_duplicate_unverified_key_returns_409(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        // Add the key but do NOT call markVerified — it stays unverified
        $this->deviceKeys->add($this->accountId, $edPkHex);

        $handler = new AddDeviceHandler(
            $this->deviceKeys,
            $this->challenges,
            $this->crypto,
            $this->settings,
        );

        $request = (new ServerRequestFactory())->createServerRequest('POST', '/account/devices')
            ->withAttribute('account_id', $this->accountId)
            ->withParsedBody(['device_public_key' => $edPkHex]);

        $response = $handler($request);

        $this->assertSame(409, $response->getStatusCode());
        $error = json_decode((string) $response->getBody(), true)['error'];
        $this->assertSame('KEY_EXISTS', $error['code']);
    }
}
