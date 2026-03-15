<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Relay\Service\CryptoService;

final class CryptoServiceTest extends TestCase
{
    private CryptoService $crypto;

    protected function setUp(): void
    {
        $this->crypto = new CryptoService();
    }

    public function test_create_challenge_returns_encrypted_nonce(): void
    {
        // Generate an Ed25519 keypair (simulating the client)
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        $challenge = $this->crypto->createChallenge($edPkHex);

        $this->assertArrayHasKey('encrypted_nonce', $challenge);
        $this->assertArrayHasKey('server_public_key', $challenge);
        $this->assertArrayHasKey('plaintext_nonce', $challenge);
    }

    public function test_client_can_decrypt_challenge(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edSk = sodium_crypto_sign_secretkey($edKp);
        $edPkHex = bin2hex($edPk);

        $challenge = $this->crypto->createChallenge($edPkHex);

        // Client-side decryption
        $clientX25519Sk = sodium_crypto_sign_ed25519_sk_to_curve25519($edSk);
        $serverX25519Pk = hex2bin($challenge['server_public_key']); // 32-byte public key directly

        // The encrypted_nonce is: nonce (24 bytes) || ciphertext
        $blob = hex2bin($challenge['encrypted_nonce']);
        $boxNonce = substr($blob, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_BOX_NONCEBYTES);

        // Build keypair for crypto_box_open: client sk + server pk
        $decryptKp = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $clientX25519Sk,
            $serverX25519Pk
        );
        $decrypted = sodium_crypto_box_open($ciphertext, $boxNonce, $decryptKp);

        $this->assertSame(
            $challenge['plaintext_nonce'],
            bin2hex($decrypted)
        );
    }

    public function test_verify_nonce_succeeds_with_correct_value(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edPkHex = bin2hex($edPk);

        $challenge = $this->crypto->createChallenge($edPkHex);

        $this->assertTrue(
            $this->crypto->verifyNonce(
                $challenge['plaintext_nonce'],
                $challenge['plaintext_nonce']
            )
        );
    }

    public function test_verify_nonce_fails_with_wrong_value(): void
    {
        $this->assertFalse(
            $this->crypto->verifyNonce('aabbccdd', '11223344')
        );
    }
}
