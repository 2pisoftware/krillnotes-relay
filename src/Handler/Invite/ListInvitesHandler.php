<?php
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
        private readonly array $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $baseUrl   = rtrim($this->settings['base_url'], '/');
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
