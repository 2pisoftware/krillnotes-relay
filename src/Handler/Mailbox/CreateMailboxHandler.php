<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);
namespace Relay\Handler\Mailbox;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\MailboxRepository;
use Slim\Psr7\Response;
final class CreateMailboxHandler
{
    public function __construct(private readonly MailboxRepository $mailboxes) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $body = $request->getParsedBody();
        $workspaceId = $body['workspace_id'] ?? '';
        if (!$workspaceId) {
            $response = new Response(400);
            $response->getBody()->write(json_encode(['error' => ['code' => 'MISSING_FIELDS', 'message' => 'workspace_id is required']]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        $this->mailboxes->create($accountId, $workspaceId);
        $response = new Response(201);
        $response->getBody()->write(json_encode(['data' => ['workspace_id' => $workspaceId]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
