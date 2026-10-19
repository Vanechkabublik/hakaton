<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Seed\DefaultDataSeeder;

final class SeedCommand implements Command
{
    public function __construct(private readonly DefaultDataSeeder $seeder)
    {
    }

    public function name(): string
    {
        return 'seed';
    }

    public function description(): string
    {
        return 'Создать начальные данные: рубрики, тарифы, страницы, администратора (ADMIN_EMAIL)';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        foreach ($this->seeder->run() as $line) {
            $out->line($line);
        }

        return 0;
    }
}
