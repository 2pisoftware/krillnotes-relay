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
use Slim\Psr7\Response;

final class ListInvitesHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $uri       = $request->getUri();
        $baseUrl   = $uri->getScheme() . '://' . $uri->getAuthority();
        $rows      = $this->invites->listForAccount($accountId);

        $data = array_map(fn($row) => [
            'invite_id'      => $row['invite_id'],
            'token'          => $row['token'],
            'url'            => "{$baseUrl}/invites/{$row['token']}",
            'expires_at'     => $row['expires_at'],
            'download_count' => (int) $row['download_count'],
            'created_at'     => $row['created_at'],
        ], $rows);

        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
