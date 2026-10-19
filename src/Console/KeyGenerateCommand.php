<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;

final class KeyGenerateCommand implements Command
{
    public function name(): string
    {
        return 'key:generate';
    }

    public function description(): string
    {
        return 'Сгенерировать значение APP_KEY';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $out->line('base64:' . base64_encode(random_bytes(32)));

        return 0;
    }
}
