<?php
declare(strict_types=1);
namespace Relay\Handler\Account;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\AccountRepository;
use Slim\Psr7\Response;
final class DeleteAccountHandler
{
    public function __construct(private readonly AccountRepository $accounts) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $this->accounts->flagForDeletion($accountId);
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => ['message' => 'Account flagged for deletion']]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
