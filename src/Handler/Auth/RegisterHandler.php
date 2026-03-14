<?php
declare(strict_types=1);
namespace Relay\Handler\Auth;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\AccountRepository;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\AuthService;
use Relay\Service\CryptoService;
use Slim\Psr7\Response;
final class RegisterHandler
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly ChallengeRepository $challenges,
        private readonly AuthService $auth,
        private readonly CryptoService $crypto,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $email = $body['email'] ?? '';
        $password = $body['password'] ?? '';
        $identityUuid = $body['identity_uuid'] ?? '';
        $devicePublicKey = $body['device_public_key'] ?? '';
        if (!$email || !$password || !$identityUuid || !$devicePublicKey) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'email, password, identity_uuid, and device_public_key are required']]);
        }
        if ($this->accounts->findByEmail($email) !== null) {
            return $this->json(409, ['error' => ['code' => 'EMAIL_EXISTS', 'message' => 'An account with this email already exists']]);
        }
        $hash = $this->auth->hashPassword($password);
        $accountId = $this->accounts->create($email, $hash, $identityUuid);
        $this->deviceKeys->add($accountId, $devicePublicKey);
        $challenge = $this->crypto->createChallenge($devicePublicKey);
        $this->challenges->create($accountId, $devicePublicKey, $challenge['plaintext_nonce'], $challenge['server_public_key'], 'registration', $this->settings['auth']['challenge_lifetime_seconds']);
        return $this->json(201, ['data' => ['account_id' => $accountId, 'challenge' => ['encrypted_nonce' => $challenge['encrypted_nonce'], 'server_public_key' => $challenge['server_public_key']]]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
