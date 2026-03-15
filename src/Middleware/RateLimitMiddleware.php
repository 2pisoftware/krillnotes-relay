<?php

// This Source Code Form is subject to the terms of the Mozilla Public
// License, v. 2.0. If a copy of the MPL was not distributed with this
// file, You can obtain one at https://mozilla.org/MPL/2.0/.
//
// Copyright (c) 2024-2026 TripleACS Pty Ltd t/a 2pi Software

declare(strict_types=1);

namespace Relay\Middleware;

use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $minIntervalSeconds = 60,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $accountId = $request->getAttribute('account_id');

        // Check last poll time
        $stmt = $this->pdo->prepare(
            "SELECT last_poll_at FROM accounts WHERE account_id = ?"
        );
        $stmt->execute([$accountId]);
        $lastPoll = $stmt->fetchColumn();

        if ($lastPoll !== false && $lastPoll !== null) {
            $elapsed = time() - strtotime($lastPoll);
            if ($elapsed < $this->minIntervalSeconds) {
                $retryAfter = $this->minIntervalSeconds - $elapsed;
                $response = new Response(429);
                $response->getBody()->write(json_encode([
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => "Poll interval minimum is {$this->minIntervalSeconds}s",
                        'retry_after' => $retryAfter,
                    ],
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('Retry-After', (string) $retryAfter);
            }
        }

        // Update last poll time
        $stmt = $this->pdo->prepare(
            "UPDATE accounts SET last_poll_at = datetime('now')
             WHERE account_id = ?"
        );
        $stmt->execute([$accountId]);

        return $handler->handle($request);
    }
}
