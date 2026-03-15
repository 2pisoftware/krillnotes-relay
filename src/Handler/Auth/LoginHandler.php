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
use Relay\Repository\SessionRepository;
use Relay\Service\AuthService;
use Slim\Psr7\Response;
final class LoginHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly SessionRepository $sessions,
        private readonly AuthService $auth,
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
        return $this->json(200, ['data' => ['session_token' => $token]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
