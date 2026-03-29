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
final class VerifyDeviceHandler
{
    public function __construct(
        private readonly ChallengeRepository $challenges,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly CryptoService $crypto,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $devicePublicKey = $body['device_public_key'] ?? '';
        $nonceResponse = $body['nonce'] ?? '';
        if (!$devicePublicKey || !$nonceResponse) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'device_public_key and nonce are required']]);
        }
        $challenge = $this->challenges->findValid($devicePublicKey, 'device_add');
        if ($challenge === null) {
            return $this->json(404, ['error' => ['code' => 'NO_CHALLENGE', 'message' => 'No pending challenge for this device key']]);
        }
        // Verify the challenge belongs to the authenticated account
        if ($challenge['account_id'] !== $request->getAttribute('account_id')) {
            return $this->json(403, [
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Challenge does not belong to this account',
                ],
            ]);
        }
        if (!$this->crypto->verifyNonce($challenge['nonce'], $nonceResponse)) {
            return $this->json(403, ['error' => ['code' => 'INVALID_NONCE', 'message' => 'Proof of possession failed']]);
        }
        $deviceId = ($body['device_id'] ?? '') ?: null;
        $this->deviceKeys->markVerified($devicePublicKey, $deviceId);
        $this->challenges->delete((int) $challenge['id']);
        return $this->json(200, ['data' => ['ok' => true]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
