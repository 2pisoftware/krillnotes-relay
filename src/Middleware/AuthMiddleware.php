<?php

declare(strict_types=1);

namespace Relay\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Relay\Repository\SessionRepository;
use Slim\Psr7\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SessionRepository $sessions
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $header = $request->getHeaderLine('Authorization');

        if (!str_starts_with($header, 'Bearer ')) {
            return $this->unauthorized('Missing authorization header');
        }

        $token = substr($header, 7);
        $session = $this->sessions->findValid($token);

        if ($session === null) {
            return $this->unauthorized('Invalid or expired session');
        }

        $request = $request
            ->withAttribute('account_id', $session['account_id'])
            ->withAttribute('session_token', $token);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => ['code' => 'UNAUTHORIZED', 'message' => $message],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
