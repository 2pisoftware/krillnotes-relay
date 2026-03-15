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
final class ListMailboxesHandler
{
    public function __construct(private readonly MailboxRepository $mailboxes) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $list = $this->mailboxes->listForAccount($accountId);
        $response = new Response(200);
        $response->getBody()->write(json_encode([
            'data' => array_map(fn($m) => [
                'workspace_id' => $m['workspace_id'],
                'registered_at' => $m['registered_at'],
                'pending_bundles' => (int) $m['pending_bundles'],
                'storage_used' => (int) $m['storage_used'],
            ], $list),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
