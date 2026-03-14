<?php
declare(strict_types=1);
namespace Relay\Handler\Account;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Service\CryptoService;
use Slim\Psr7\Response;
final class AddDeviceHandler
{
    public function __construct(
        private readonly DeviceKeyRepository $deviceKeys,
        private readonly ChallengeRepository $challenges,
        private readonly CryptoService $crypto,
        private readonly array $settings,
    ) {}
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $accountId = $request->getAttribute('account_id');
        $body = $request->getParsedBody();
        $devicePublicKey = $body['device_public_key'] ?? '';
        if (!$devicePublicKey) {
            return $this->json(400, ['error' => ['code' => 'MISSING_FIELDS', 'message' => 'device_public_key is required']]);
        }
        $existing = $this->deviceKeys->findAccountByKey($devicePublicKey);
        if ($existing !== null) {
            return $this->json(409, ['error' => ['code' => 'KEY_EXISTS', 'message' => 'This device key is already registered']]);
        }
        $this->deviceKeys->add($accountId, $devicePublicKey);
        $challenge = $this->crypto->createChallenge($devicePublicKey);
        $this->challenges->create($accountId, $devicePublicKey, $challenge['plaintext_nonce'], $challenge['server_public_key'], 'device_add', $this->settings['auth']['challenge_lifetime_seconds']);
        return $this->json(201, ['data' => ['challenge' => ['encrypted_nonce' => $challenge['encrypted_nonce'], 'server_public_key' => $challenge['server_public_key']]]]);
    }
    private function json(int $status, array $data): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
