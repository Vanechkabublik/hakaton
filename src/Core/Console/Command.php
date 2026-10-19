<?php

declare(strict_types=1);

namespace App\Core\Console;

interface Command
{
    /** Имя команды: `php bin/console <name>`. */
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $args позиционные аргументы
     * @param array<string,string|true> $options опции вида --key=value / --flag
     * @return int код выхода
     */
    public function run(array $args, array $options, ConsoleOutput $out): int;
}
