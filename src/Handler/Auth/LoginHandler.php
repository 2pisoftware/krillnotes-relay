<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Handler\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\AccountRepository;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\SessionRepository;
use Relay\Service\AuthService;
use Relay\Service\CryptoService;
use Slim\Psr7\Response;
final class LoginHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SessionRepository $sessions,
        private readonly AuthService $auth,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly ChallengeRepository $challenges,
        private readonly CryptoService $crypto,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';
        if (!$email || !$password) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'email and password are required']]);
        }
        $account = $this->accounts->findByEmail($email);
        if ($account === null || !$this->auth->verifyPassword($password, $account['password_hash'])) {
            return $this->json(401, ['error' => ['code' => 'INVALID_CREDENTIALS', 'message' => 'Invalid email or password']]);
        }
        if ($account['flagged_for_deletion'] !== null) {
            return $this->json(403, ['error' => ['code' => 'ACCOUNT_DELETED', 'message' => 'Account is flagged for deletion']]);
        }
        $token = $this->sessions->create($account['account_id'], $this->settings['auth']['session_lifetime_seconds']);

        $responseData = ['session_token' => $token];

        // Conditional device registration: if client sent a device_public_key,
        // check whether it's already registered and verified.
        $devicePublicKey = $body['device_public_key'] ?? '';
        if ($devicePublicKey !== '' && ctype_xdigit($devicePublicKey) && strlen($devicePublicKey) === 64) {
            $existing = $this->deviceKeys->findByKey($devicePublicKey);
            if ($existing === null) {
                // Unknown key — insert as unverified + issue PoP challenge.
                $this->deviceKeys->add($account['account_id'], $devicePublicKey);
                $challenge = $this->crypto->createChallenge($devicePublicKey);
                $this->challenges->create(
                    $account['account_id'],
                    $devicePublicKey,
                    $challenge['plaintext_nonce'],
                    $challenge['server_public_key'],
                    'device_add',
                    $this->settings['auth']['challenge_lifetime_seconds'],
                );
                $responseData['challenge'] = [
                    'encrypted_nonce' => $challenge['encrypted_nonce'],
                    'server_public_key' => $challenge['server_public_key'],
                ];
            } elseif ($existing['account_id'] !== $account['account_id']) {
                // Key belongs to a different account — silently skip.
            } elseif (!(bool) $existing['verified']) {
                // Our key, not yet verified — issue fresh PoP challenge.
                $challenge = $this->crypto->createChallenge($devicePublicKey);
                $this->challenges->create(
                    $account['account_id'],
                    $devicePublicKey,
                    $challenge['plaintext_nonce'],
                    $challenge['server_public_key'],
                    'device_add',
                    $this->settings['auth']['challenge_lifetime_seconds'],
                );
                $responseData['challenge'] = [
                    'encrypted_nonce' => $challenge['encrypted_nonce'],
                    'server_public_key' => $challenge['server_public_key'],
                ];
            }
            // else: verified — no challenge needed
        }

        return $this->json(200, ['data' => $responseData]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
