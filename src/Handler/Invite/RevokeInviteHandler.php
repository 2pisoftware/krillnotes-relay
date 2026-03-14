<?php
declare(strict_types=1);
namespace Relay\Handler\Invite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\InviteRepository;
use Relay\Service\StorageService;
use Slim\Psr7\Response;

final class RevokeInviteHandler
{
    public function __construct(
        private readonly InviteRepository $invites,
        private readonly StorageService $storage,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $token     = $request->getAttribute('token');
        $invite    = $this->invites->findByToken($token);

        if ($invite === null) {
            return $this->json(404, ['error' => ['code' => 'NOT_FOUND', 'message' => 'Invite not found']]);
        }
        if ($invite['account_id'] !== $accountId) {
            return $this->json(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'Not your invite']]);
        }

        $blobPath = $this->invites->delete($token);
        if ($blobPath !== null) {
            $this->storage->delete($blobPath);
        }

        return $this->json(200, ['data' => ['ok' => true]]);
    }

    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
