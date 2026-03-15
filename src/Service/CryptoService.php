<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Service;

final class CryptoService
{
    /**
     * Create a proof-of-possession challenge.
     *
     * Generates a random nonce, encrypts it to the client's Ed25519 public key
     * (converted to X25519) using crypto_box with an ephemeral server keypair.
     *
     * Returns:
     * - encrypted_nonce: hex(box_nonce || ciphertext)
     * - server_public_key: hex(ephemeral X25519 public key)
     * - plaintext_nonce: hex(nonce) — stored server-side for verification
     *
     * @param string $clientEdPkHex Client's Ed25519 public key (hex)
     * @return array{encrypted_nonce: string, server_public_key: string, plaintext_nonce: string}
     */
    /**
     * @throws \InvalidArgumentException if the key is not a valid Ed25519 public key
     */
    public function createChallenge(string $clientEdPkHex): array
    {
        $clientEdPk = hex2bin($clientEdPkHex);
        if ($clientEdPk === false || strlen($clientEdPk) !== 32) {
            throw new \InvalidArgumentException('device_public_key is not valid hex or wrong length');
        }
        try {
            $clientX25519Pk = sodium_crypto_sign_ed25519_pk_to_curve25519(
                $clientEdPk
            );
        } catch (\SodiumException $e) {
            throw new \InvalidArgumentException(
                'device_public_key is not a valid Ed25519 public key: ' . $e->getMessage()
            );
        }

        // Ephemeral server X25519 keypair
        $serverKp = sodium_crypto_box_keypair();
        $serverSk = sodium_crypto_box_secretkey($serverKp);
        $serverPk = sodium_crypto_box_publickey($serverKp);

        // Random challenge nonce (32 bytes)
        $nonce = random_bytes(32);

        // Encrypt: crypto_box(nonce, box_nonce, client_pk, server_sk)
        $boxNonce = random_bytes(SODIUM_CRYPTO_BOX_NONCEBYTES);
        $encryptKp = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            $serverSk,
            $clientX25519Pk
        );
        $ciphertext = sodium_crypto_box($nonce, $boxNonce, $encryptKp);

        // Wipe secret key from memory (no-op when ext-sodium unavailable)
        if (extension_loaded('sodium')) {
            sodium_memzero($serverSk);
        }

        return [
            'encrypted_nonce' => bin2hex($boxNonce . $ciphertext),
            'server_public_key' => bin2hex($serverPk),
            'plaintext_nonce' => bin2hex($nonce),
        ];
    }

    /**
     * Constant-time comparison of nonce values.
     */
    public function verifyNonce(
        string $expectedHex,
        string $providedHex
    ): bool {
        if (strlen($expectedHex) !== strlen($providedHex)) {
            return false;
        }
        return hash_equals($expectedHex, $providedHex);
    }
}
