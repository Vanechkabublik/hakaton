<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Конфигурация приложения из переменных окружения (+ .env для локальной разработки).
 * Реальные переменные окружения всегда имеют приоритет над файлом.
 */
final class Config
{
    /** @param array<string,string> $values */
    public function __construct(
        private array $values,
        private readonly string $basePath,
    ) {
    }

    public static function load(string $basePath): self
    {
        $values = [];
        $file = $basePath . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $values[trim($key)] = self::unquote(trim($value));
            }
        }
        foreach (array_merge($_ENV, getenv()) as $key => $value) {
            if (is_string($value)) {
                $values[(string) $key] = $value;
            }
        }

        return new self($values, $basePath);
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function string(string $key, string $default = ''): string
    {
        return $this->get($key) ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): void
    {
        $this->values[$key] = $value;
    }

    public function isProduction(): bool
    {
        return $this->string('APP_ENV', 'production') === 'production';
    }

    public function isTesting(): bool
    {
        return $this->string('APP_ENV') === 'testing';
    }

    public function debug(): bool
    {
        return $this->bool('APP_DEBUG', false);
    }

    public function basePath(string $relative = ''): string
    {
        return $relative === '' ? $this->basePath : $this->basePath . '/' . ltrim($relative, '/');
    }

    public function storagePath(string $relative = ''): string
    {
        return $this->basePath('storage' . ($relative === '' ? '' : '/' . ltrim($relative, '/')));
    }

    public function appUrl(): string
    {
        return rtrim($this->string('APP_URL', 'http://localhost:8080'), '/');
    }

    public function timezone(): string
    {
        return $this->string('APP_TIMEZONE', 'Europe/Moscow');
    }
}
