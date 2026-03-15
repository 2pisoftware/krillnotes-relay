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
use Relay\Repository\PasswordResetRepository;
use Relay\Service\AuthService;
use Slim\Psr7\Response;
final class ResetPasswordConfirmHandler
{
    public function __construct(
        private readonly PasswordResetRepository $resets,
        private readonly AccountRepository $accounts,
        private readonly AuthService $auth,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $token = $body['token'] ?? '';
        $newPassword = $body['new_password'] ?? '';
        if (!$token || !$newPassword) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'token and new_password are required']]);
        }
        $reset = $this->resets->findValid($token);
        if ($reset === null) {
            return $this->json(404, ['error' => ['code' => 'INVALID_TOKEN', 'message' => 'Reset token is invalid or expired']]);
        }
        $hash = $this->auth->hashPassword($newPassword);
        $this->accounts->updatePassword($reset['account_id'], $hash);
        $this->resets->markUsed($token);
        return $this->json(200, ['data' => ['ok' => true]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
