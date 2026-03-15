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
use Relay\Repository\AccountRepository;
use Relay\Repository\DeviceKeyRepository;
use Slim\Psr7\Response;
final class GetAccountHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly DeviceKeyRepository $deviceKeys,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $account = $this->accounts->findById($accountId);
        $keys = $this->deviceKeys->listForAccount($accountId);
        $response = new Response(200);
        $response->getBody()->write(json_encode([
            'data' => [
                'account_id' => $account['account_id'],
                'email' => $account['email'],
                'identity_uuid' => $account['identity_uuid'],
                'role' => $account['role'],
                'device_keys' => array_map(
                    fn($k) => [
                        'device_public_key' => $k['device_public_key'],
                        'verified' => (bool) $k['verified'],
                        'added_at' => $k['added_at'],
                    ],
                    $keys
                ),
                'storage_used' => (int) $account['storage_used'],
                'flagged_for_deletion' => $account['flagged_for_deletion'],
                'created_at' => $account['created_at'],
            ],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
