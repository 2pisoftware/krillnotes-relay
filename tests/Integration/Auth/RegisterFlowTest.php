<?php
declare(strict_types=1);
namespace Relay\Tests\Integration\Auth;
use PHPUnit\Framework\TestCase;
use Relay\Database\Connection;
use Relay\Database\Migrator;
use Relay\Handler\Auth\RegisterHandler;
use Relay\Handler\Auth\RegisterVerifyHandler;
use Relay\Repository\AccountRepository;
use Relay\Repository\ChallengeRepository;
use Relay\Repository\DeviceKeyRepository;
use Relay\Repository\SessionRepository;
use Relay\Service\AuthService;
use Relay\Service\CryptoService;
use Slim\Psr7\Factory\ServerRequestFactory;
final class RegisterFlowTest extends TestCase
{
    private \PDO $pdo;
    private array $settings;
    protected function setUp(): void
    {
        $this->pdo = Connection::create(':memory:');
        (new Migrator($this->pdo, dirname(__DIR__, 3) . '/migrations'))->run();
        $this->settings = require dirname(__DIR__, 3) . '/config/settings.php';
    }
    public function test_full_registration_with_proof_of_possession(): void
    {
        $edKp = sodium_crypto_sign_keypair();
        $edPk = sodium_crypto_sign_publickey($edKp);
        $edSk = sodium_crypto_sign_secretkey($edKp);
        $edPkHex = bin2hex($edPk);
        $registerHandler = new RegisterHandler(
            new AccountRepository($this->pdo),
            new DeviceKeyRepository($this->pdo),
            new ChallengeRepository($this->pdo),
            new AuthService(),
            new CryptoService(),
            $this->settings,
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register')
            ->withParsedBody(['email' => 'alice@example.com', 'password' => 'securepassword123', 'identity_uuid' => 'id-uuid-alice', 'device_public_key' => $edPkHex]);
        $response = $registerHandler($request);
        $this->assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true)['data'];
        $accountId = $data['account_id'];
        $encryptedNonce = $data['challenge']['encrypted_nonce'];
        $serverPkHex = $data['challenge']['server_public_key'];
        // Client decrypts challenge
        $clientX25519Sk = sodium_crypto_sign_ed25519_sk_to_curve25519($edSk);
        $serverX25519Pk = hex2bin($serverPkHex);
        $blob = hex2bin($encryptedNonce);
        $boxNonce = substr($blob, 0, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $ciphertext = substr($blob, SODIUM_CRYPTO_BOX_NONCEBYTES);
        $decryptKp = sodium_crypto_box_keypair_from_secretkey_and_publickey($clientX25519Sk, $serverX25519Pk);
        $plaintext = sodium_crypto_box_open($ciphertext, $boxNonce, $decryptKp);
        $this->assertNotFalse($plaintext);
        $verifyHandler = new RegisterVerifyHandler(
            new ChallengeRepository($this->pdo),
            new DeviceKeyRepository($this->pdo),
            new SessionRepository($this->pdo),
            new CryptoService(),
            $this->settings,
        );
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/auth/register/verify')
            ->withParsedBody(['device_public_key' => $edPkHex, 'nonce' => bin2hex($plaintext)]);
        $response = $verifyHandler($request);
        $this->assertSame(200, $response->getStatusCode());
        $verifyData = json_decode((string) $response->getBody(), true)['data'];
        $this->assertSame($accountId, $verifyData['account_id']);
        $this->assertNotEmpty($verifyData['session_token']);
        // Verify device key is now verified in DB
        $found = (new DeviceKeyRepository($this->pdo))->findAccountByKey($edPkHex);
        $this->assertNotNull($found);
    }
}
