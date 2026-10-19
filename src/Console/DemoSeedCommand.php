<?php

declare(strict_types=1);

namespace App\Console;

use App\Core\Console\Command;
use App\Core\Console\ConsoleOutput;
use App\Seed\DemoContentSeeder;

final class DemoSeedCommand implements Command
{
    public function __construct(private readonly DemoContentSeeder $seeder)
    {
    }

    public function name(): string
    {
        return 'demo:seed';
    }

    public function description(): string
    {
        return 'Наполнить сайт демо-материалами (для разработки и показа)';
    }

    public function run(array $args, array $options, ConsoleOutput $out): int
    {
        $count = $this->seeder->run((int) ($options['articles'] ?? 24));
        $out->info("Создано демо-материалов: {$count}");

        return 0;
    }
}
