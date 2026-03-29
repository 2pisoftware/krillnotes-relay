<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Handler\Bundle;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\BundleRepository;
use Relay\Repository\DeviceKeyRepository;
use Slim\Psr7\Response;
final class ListBundlesHandler
{
    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly DeviceKeyRepository $deviceKeys,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $deviceId = $request->getQueryParams()['device_id'] ?? null;
        $keys = $this->deviceKeys->listForAccount($accountId);
        $verifiedKeys = array_column(
            array_filter($keys, fn($k) => (bool) $k['verified']),
            'device_public_key'
        );
        $bundles = $this->bundles->listForRecipientKeys($verifiedKeys, $deviceId !== '' ? $deviceId : null);
        $response = new Response(200);
        $response->getBody()->write(json_encode([
            'data' => array_map(fn($b) => [
                'bundle_id' => $b['bundle_id'],
                'workspace_id' => $b['workspace_id'],
                'sender_device_key' => $b['sender_device_key'],
                'mode' => $b['mode'],
                'size_bytes' => (int) $b['size_bytes'],
                'created_at' => $b['created_at'],
            ], $bundles),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
