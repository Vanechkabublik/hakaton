<?php

declare(strict_types=1);

namespace App\Settings;

use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Security\Crypto;

/**
 * Настройки сайта. Приоритет: значение из БД (админка) → переменная окружения → значение по умолчанию.
 * Секреты хранятся зашифрованными (libsodium) и расшифровываются только при чтении.
 */
final class SettingsService
{
    /** @var array<string,string>|null */
    private ?array $stored = null;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Crypto $crypto,
    ) {
    }

    public function get(string $key, ?string $default = null): string
    {
        $stored = $this->load();
        if (array_key_exists($key, $stored) && $stored[$key] !== '') {
            $definition = SettingsRegistry::get($key);
            if ($definition !== null && $definition->isSecret()) {
                return $this->crypto->decrypt($stored[$key]) ?? '';
            }

            return $stored[$key];
        }
        $definition = SettingsRegistry::get($key);
        if ($definition === null) {
            return $default ?? '';
        }
        if ($definition->env !== null) {
            $env = $this->config->get($definition->env);
            if ($env !== null) {
                return $env;
            }
        }

        return $default ?? $definition->default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === '' ? $default : (int) $value;
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower($this->get($key)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Задана ли явно настройка (в БД или окружении). */
    public function has(string $key): bool
    {
        return $this->get($key) !== '';
    }

    public function set(string $key, string $value, ?int $userId = null): void
    {
        $definition = SettingsRegistry::get($key);
        $isSecret = $definition?->isSecret() ?? false;
        $stored = $isSecret && $value !== '' ? $this->crypto->encrypt($value) : $value;
        $this->db->execute(
            'INSERT INTO settings (key, value, is_secret, updated_at, updated_by)
             VALUES (:key, :value, :secret, now(), :user)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, is_secret = EXCLUDED.is_secret,
                 updated_at = now(), updated_by = EXCLUDED.updated_by',
            ['key' => $key, 'value' => $stored, 'secret' => $isSecret, 'user' => $userId]
        );
        $this->stored = null;
    }

    /**
     * Сохранить набор значений из формы админки. Пустое значение секрета оставляет прежнее.
     *
     * @param array<string,mixed> $input
     * @param list<string> $keys
     */
    public function saveMany(array $input, array $keys, ?int $userId): void
    {
        $this->db->transaction(function () use ($input, $keys, $userId): void {
            foreach ($keys as $key) {
                $definition = SettingsRegistry::get($key);
                if ($definition === null) {
                    continue;
                }
                $formKey = str_replace('.', '__', $key);
                if ($definition->type === 'bool') {
                    $this->set($key, !empty($input[$formKey]) ? '1' : '0', $userId);
                    continue;
                }
                if ($definition->type === 'image') {
                    continue; // загружается отдельным шагом
                }
                $value = $input[$formKey] ?? null;
                if (!is_string($value)) {
                    continue;
                }
                $value = trim($value);
                if ($definition->isSecret() && $value === '') {
                    continue;
                }
                if ($definition->type === 'int' && $value !== '' && !ctype_digit($value)) {
                    continue;
                }
                if ($definition->type === 'select' && !array_key_exists($value, $definition->options)) {
                    continue;
                }
                $this->set($key, $value, $userId);
            }
        });
    }

    public function isSecretSet(string $key): bool
    {
        return $this->get($key) !== '';
    }

    /** Значения для публичных страниц, которые нужны на каждой странице (кешируются на время запроса). */
    public function siteName(): string
    {
        return $this->get('site.name');
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->db->fetchAll('SELECT key, value FROM settings') as $row) {
                $this->stored[(string) $row['key']] = (string) $row['value'];
            }
        }

        return $this->stored;
    }
}
