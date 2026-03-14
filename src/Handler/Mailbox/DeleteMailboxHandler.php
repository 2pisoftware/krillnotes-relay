<?php
declare(strict_types=1);
namespace Relay\Handler\Mailbox;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\MailboxRepository;
use Slim\Psr7\Response;
final class DeleteMailboxHandler
{
    public function __construct(private readonly MailboxRepository $mailboxes) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $workspaceId = $request->getAttribute('workspace_id');
        $deleted = $this->mailboxes->delete($accountId, $workspaceId);
        $status = $deleted ? 200 : 404;
        $response = new Response($status);
        $body = $deleted ? ['data' => ['ok' => true]] : ['error' => ['code' => 'NOT_FOUND', 'message' => 'Mailbox not found']];
        $response->getBody()->write(json_encode($body));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
