<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Container;
use Throwable;

final class ConsoleApplication
{
    /** @var array<string,Command> */
    private array $commands = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** @param class-string<Command> $class */
    public function register(string $class): void
    {
        $command = $this->container->get($class);
        $this->commands[$command->name()] = $command;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $out = new ConsoleOutput();
        array_shift($argv);
        $name = array_shift($argv) ?? 'list';

        if ($name === 'list' || $name === 'help' || $name === '--help') {
            $out->line('Команды:');
            ksort($this->commands);
            foreach ($this->commands as $command) {
                $out->line(sprintf('  %-26s %s', $command->name(), $command->description()));
            }

            return 0;
        }
        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $out->error("Неизвестная команда «{$name}». Список: php bin/console list");

            return 1;
        }

        $args = [];
        $options = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', substr($arg, 2), 2);
                $options[$parts[0]] = $parts[1] ?? true;
            } else {
                $args[] = $arg;
            }
        }

        try {
            return $command->run($args, $options, $out);
        } catch (Throwable $e) {
            $out->error($e::class . ': ' . $e->getMessage());
            $out->error($e->getFile() . ':' . $e->getLine());

            return 1;
        }
    }
}
