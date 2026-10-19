<?php

declare(strict_types=1);

namespace App\Console;

use App\Auth\TwoFactorService;
use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\User\UserRepository;

final class UserResetTwoFactorCommand implements Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    public function name(): string
    {
        return 'user:reset-2fa';
    }

    public function description(): string
    {
        return 'Сбросить 2FA пользователя (если потерян доступ к приложению и кодам восстановления)';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $user = $this->users->findByEmail($args[0] ?? '');
        if ($user === null) {
            $out->error('Пользователь не найден');

            return 1;
        }
        $this->twoFactor->disable($user);
        $out->info("2FA для {$user->email} сброшена. При следующем входе в админку потребуется настроить её заново.");

        return 0;
    }
}
