<?php
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
