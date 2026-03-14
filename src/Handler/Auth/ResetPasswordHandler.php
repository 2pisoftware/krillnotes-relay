<?php
declare(strict_types=1);
namespace Relay\Handler\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\AccountRepository;
use Relay\Repository\PasswordResetRepository;
use Slim\Psr7\Response;
final class ResetPasswordHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly PasswordResetRepository $resets,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = $body['email'] ?? '';
        // Always return 200 to prevent email enumeration
        $response = new Response(200);
        $response->getBody()->write(json_encode(['data' => ['message' => 'If the email exists, a reset link has been sent']]));
        $response = $response->withHeader('Content-Type', 'application/json');
        if (!$email) { return $response; }
        $account = $this->accounts->findByEmail($email);
        if ($account === null) { return $response; }
        $token = $this->resets->create($account['account_id'], $this->settings['auth']['reset_token_lifetime_seconds']);
        // TODO: Send email with reset link containing $token
        return $response;
    }
}
