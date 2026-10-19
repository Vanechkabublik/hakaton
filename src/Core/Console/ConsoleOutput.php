<?php

declare(strict_types=1);

namespace App\Core\Console;

final class ConsoleOutput
{
    public function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->color('32', $text));
    }

    public function warn(string $text): void
    {
        $this->line($this->color('33', $text));
    }

    public function error(string $text): void
    {
        fwrite(STDERR, $this->color('31', $text) . PHP_EOL);
    }

    private function color(string $code, string $text): string
    {
        return stream_isatty(STDOUT) ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
