<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class CreateInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body      = $request->getParsedBody();
        $accountId = $request->getAttribute('account_id');
        $payload   = $body['payload'] ?? '';
        $expiresAt = $body['expires_at'] ?? '';

        if (!$payload || !$expiresAt) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'payload and expires_at are required']]);
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return $this->json(400, ['error' => ['code' => 'INVALID_PAYLOAD', 'message' => 'payload must be valid base64']]);
        }

        $maxSize = $this->settings['limits']['max_bundle_size_bytes'];
        if (strlen($decoded) > $maxSize) {
            return $this->json(413, ['error' => ['code' => 'PAYLOAD_TOO_LARGE', 'message' => "Payload exceeds {$maxSize} bytes"]]);
        }

        $expireTs = strtotime($expiresAt);
        if ($expireTs === false || $expireTs <= time()) {
            return $this->json(400, ['error' => ['code' => 'INVALID_EXPIRY', 'message' => 'expires_at must be a future ISO 8601 datetime']]);
        }
        if ($expireTs > strtotime('+90 days')) {
            return $this->json(400, ['error' => ['code' => 'INVALID_EXPIRY', 'message' => 'expires_at cannot be more than 90 days from now']]);
        }

        $token    = bin2hex(random_bytes(32));
        $inviteId = \Ramsey\Uuid\Uuid::uuid4()->toString();
        $blobPath = $this->storage->store($inviteId, $decoded);
        $dbExpiry = date('Y-m-d H:i:s', $expireTs);

        $this->invites->create($inviteId, $accountId, $token, $blobPath, strlen($decoded), $dbExpiry);

        $baseUrl = rtrim($this->settings['base_url'], '/');
        return $this->json(201, ['data' => [
            'invite_id'  => $inviteId,
            'token'      => $token,
            'url'        => "{$baseUrl}/invites/{$token}",
            'expires_at' => $expiresAt,
        ]]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
