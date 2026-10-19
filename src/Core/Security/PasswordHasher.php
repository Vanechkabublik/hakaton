<?php

declare(strict_types=1);

namespace App\Core\Security;

final class PasswordHasher
{
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
        password_verify($password, '$2y$12$abcdefghijklmnopqrstuuG3iTr6w6hDk0F2s3GJdU7sFQh2uYoPW');
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
