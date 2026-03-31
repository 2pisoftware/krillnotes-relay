<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Handler\Account;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\CryptoService;
use Slim\Psr7\Response;
final class AddDeviceHandler
{
    public function __construct(
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly ChallengeRepository $challenges,
        private readonly CryptoService $crypto,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $body = $request->getParsedBody();
        $devicePublicKey = $body['device_public_key'] ?? '';
        if (!$devicePublicKey) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'device_public_key is required']]);
        }
        // Validate device_public_key is a 64-char hex string (32 bytes = Ed25519 public key)
        if (!ctype_xdigit($devicePublicKey) || strlen($devicePublicKey) !== 64) {
            return $this->json(400, [
                'error' => [
                    'code' => 'INVALID_DEVICE_KEY',
                    'message' => 'device_public_key must be a 64-character hex string (32-byte Ed25519 public key)',
                ],
            ]);
        }
        $existing = $this->deviceKeys->findByKey($devicePublicKey);
        if ($existing !== null) {
            // If the key belongs to the same account and is NOT yet verified,
            // re-issue a PoP challenge so the client can complete verification.
            if ($existing['account_id'] === $accountId && !(bool) $existing['verified']) {
                $challenge = $this->crypto->createChallenge($devicePublicKey);
                $this->challenges->create($accountId, $devicePublicKey, $challenge['plaintext_nonce'], $challenge['server_public_key'], 'device_add', $this->settings['auth']['challenge_lifetime_seconds']);
                return $this->json(200, ['data' => ['challenge' => ['encrypted_nonce' => $challenge['encrypted_nonce'], 'server_public_key' => $challenge['server_public_key']]]]);
            }
            return $this->json(409, ['error' => ['code' => 'KEY_EXISTS', 'message' => 'This device key is already registered']]);
        }
        $deviceId = ($body['device_id'] ?? '') ?: null;
        if ($deviceId !== null && strlen($deviceId) > 128) {
            $deviceId = null; // silently ignore oversized values
        }
        $this->deviceKeys->add($accountId, $devicePublicKey, $deviceId);
        $challenge = $this->crypto->createChallenge($devicePublicKey);
        $this->challenges->create($accountId, $devicePublicKey, $challenge['plaintext_nonce'], $challenge['server_public_key'], 'device_add', $this->settings['auth']['challenge_lifetime_seconds']);
        return $this->json(201, ['data' => ['challenge' => ['encrypted_nonce' => $challenge['encrypted_nonce'], 'server_public_key' => $challenge['server_public_key']]]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
