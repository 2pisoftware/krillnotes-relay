<?php
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
