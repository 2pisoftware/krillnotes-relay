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
use Relay\Repository\SessionRepository;
use Slim\Psr7\Response;
final class LogoutHandler
{
    public function __construct(private readonly SessionRepository $sessions) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $token = $request->getAttribute('session_token');
        $this->sessions->delete($token);
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => ['ok' => true]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
