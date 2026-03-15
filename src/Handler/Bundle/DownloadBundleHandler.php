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
use Relay\Service\StorageService;
use Slim\Psr7\Response;
final class DownloadBundleHandler
{
    public function __construct(
        private readonly BundleRepository $bundles,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly StorageService $storage,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $bundleId = $request->getAttribute('bundle_id');
        $bundle = $this->bundles->findById($bundleId);
        if ($bundle === null) {
            return $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Bundle not found']]);
        }
        $ownerCheck = $this->deviceKeys->findByKey($bundle['recipient_device_key']);
        if ($ownerCheck === null || $ownerCheck['account_id'] !== $accountId) {
            return $this->json(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your bundle']]);
        }
        $data = $this->storage->read($bundle['blob_path']);
        if ($data === null) {
            return $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Bundle file missing']]);
        }
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => [
            'bundle_id' => $bundle['bundle_id'],
            'workspace_id' => $bundle['workspace_id'],
            'sender_device_key' => $bundle['sender_device_key'],
            'mode' => $bundle['mode'],
            'payload' => base64_encode($data),
        ]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
