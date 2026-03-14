<?php
declare(strict_types=1);
namespace Relay\Service;
final class AuthService
{
    public function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? \PASSWORD_ARGON2ID : \PASSWORD_BCRYPT;
        return password_hash($password, $algo);
    }
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
