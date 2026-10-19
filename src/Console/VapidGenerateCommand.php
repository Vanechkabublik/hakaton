<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Notification\PushService;

final class VapidGenerateCommand implements Command
{
    public function name(): string
    {
        return 'vapid:generate';
    }

    public function description(): string
    {
        return 'Сгенерировать VAPID-ключи для push-уведомлений';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $keys = PushService::generateKeys();
        $out->line('VAPID_PUBLIC_KEY=' . $keys['publicKey']);
        $out->line('VAPID_PRIVATE_KEY=' . $keys['privateKey']);

        return 0;
    }
}
