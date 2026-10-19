<?php

declare(strict_types=1);

namespace App\Core;

use Stringable;
use Throwable;

/** Простой JSON-логгер: stderr (docker logs) и файл storage/logs/app-ГГГГ-ММ-ДД.log. */
final class Logger
{
    private const LEVELS = ['debug' => 100, 'info' => 200, 'warning' => 300, 'error' => 400, 'critical' => 500];

    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string|Stringable $message, array $context = []): void
    {
        $min = self::LEVELS[$this->config->string('LOG_LEVEL', 'info')] ?? 200;
        if ((self::LEVELS[$level] ?? 200) < $min) {
            return;
        }
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            $e = $context['exception'];
            $context['exception'] = [
                'class' => $e::class,
                'message' => $e->getMessage(),
                'file' => str_replace($this->config->basePath() . '/', '', $e->getFile()) . ':' . $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString()),
            ];
        }
        $line = json_encode([
            'time' => gmdate('c'),
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";

        $channels = array_filter(array_map('trim', explode(',', $this->config->string('LOG_CHANNELS', 'file'))));
        if (in_array('file', $channels, true)) {
            $dir = $this->config->storagePath('logs');
            if (is_dir($dir) || @mkdir($dir, 0775, true)) {
                @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
            }
        }
        if (in_array('stderr', $channels, true)) {
            @file_put_contents('php://stderr', $line);
        }
    }

    /** @param array<string,mixed> $context */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }
}
