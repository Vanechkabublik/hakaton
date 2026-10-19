<?php

declare(strict_types=1);

namespace App\Core\Security;

final class PasswordHasher
{
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$ZDhyWFJZOGQ5dXd4TEYvUQ$wKDPJQMIkw3Z3/KcmeBtpy+0wrstAaOh2Fvo2ovzVLc';
    private const DUMMY_BCRYPT = '$2y$12$abcdefghijklmnopqrstuuG3iTr6w6hDk0F2s3GJdU7sFQh2uYoPW';

    public function hash(string $password): string
    {
        return password_hash($password, $this->algorithm());
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm());
    }

    /**
     * Проверка с постоянным временем для несуществующих пользователей — чтобы по времени ответа
     * нельзя было определить, зарегистрирован ли email.
     */
    public function dummyVerify(string $password): void
    {
        // Хеш того же алгоритма и стоимости, что и у настоящих паролей, — время проверки не отличается
        password_verify($password, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT);
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
