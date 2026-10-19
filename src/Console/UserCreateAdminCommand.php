<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\PasswordPolicy;
use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Core\Security\PasswordHasher;
use App\User\Role;
use App\User\UserRepository;

final class UserCreateAdminCommand implements Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly PasswordPolicy $policy,
    ) {
    }

    public function name(): string
    {
        return 'user:create-admin';
    }

    public function description(): string
    {
        return 'Создать администратора или назначить роль и пароль: user:create-admin EMAIL [--name=Имя] [--password=...] [--role=admin|editor|author]';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $email = $args[0] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out->error('Укажите email: php bin/console user:create-admin admin@example.com');

            return 1;
        }
        $role = Role::tryFrom((string) ($options['role'] ?? 'admin')) ?? Role::Admin;
        $password = is_string($options['password'] ?? null) ? $options['password'] : bin2hex(random_bytes(8)) . 'Aa1';
        if (($problem = $this->policy->validate($password, $email)) !== null) {
            $out->error($problem);

            return 1;
        }
        $existing = $this->users->findByEmail($email);
        if ($existing === null) {
            $user = $this->users->createUser([
                'email' => $email,
                'name' => is_string($options['name'] ?? null) ? $options['name'] : 'Администратор',
                'password_hash' => $this->hasher->hash($password),
                'role' => $role->value,
                'email_verified' => true,
                'digest_frequency' => 'none',
            ]);
            $this->users->ensureSlug($user);
            $out->info("Создан {$role->value}: {$email}");
        } else {
            $this->users->update($existing->id, [
                'role' => $role->value,
                'password_hash' => $this->hasher->hash($password),
                'email_verified_at' => $existing->emailVerifiedAt ?? new \DateTimeImmutable(),
                'status' => 'active',
            ]);
            $out->info("Обновлён пользователь {$email}: роль {$role->value}");
        }
        if (!isset($options['password'])) {
            $out->line("Пароль: {$password}");
        }

        return 0;
    }
}
