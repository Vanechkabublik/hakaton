<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Config;
use RuntimeException;

/**
 * Симметричное шифрование (libsodium secretbox) и HMAC-подписи на основе APP_KEY.
 * Используется для секретов в настройках, TOTP-секретов, подписанных ссылок (предпросмотр, отписка).
 */
final class Crypto
{
    private string $key;
    private string $signKey;

    public function __construct(Config $config)
    {
        $raw = $config->string('APP_KEY');
        if (str_starts_with($raw, 'base64:')) {
            $decoded = base64_decode(substr($raw, 7), true);
            $raw = $decoded === false ? '' : $decoded;
        }
        if (strlen($raw) < 32) {
            if ($config->isProduction()) {
                throw new RuntimeException('APP_KEY не задан или короче 32 байт. Выполните: php bin/console key:generate');
            }
            $raw = hash('sha256', 'insecure-dev-key-' . $config->basePath(), true);
        }
        $this->key = hash_hkdf('sha256', $raw, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'news.encrypt');
        $this->signKey = hash_hkdf('sha256', $raw, 32, 'news.sign');
    }

    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return 'enc1:' . rtrim(strtr(base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key)), '+/', '-_'), '=');
    }

    public function decrypt(string $payload): ?string
    {
        if (!str_starts_with($payload, 'enc1:')) {
            return null;
        }
        $raw = base64_decode(strtr(substr($payload, 5), '-_', '+/'), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);

        return $plain === false ? null : $plain;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, 'enc1:');
    }

    /** HMAC-подпись произвольной строки (URL-safe). */
    public function sign(string $data): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, $this->signKey, true)), '+/', '-_'), '=');
    }

    public function verify(string $data, string $signature): bool
    {
        return hash_equals($this->sign($data), $signature);
    }

    /** Подписанная ссылка вида «значение.подпись» с опциональным сроком действия. */
    public function signedToken(string $value, ?int $ttlSeconds = null): string
    {
        $expires = $ttlSeconds === null ? '0' : (string) (time() + $ttlSeconds);
        $payload = $value . '|' . $expires;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=') . '.' . $this->sign($payload);
    }

    public function readSignedToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($payload === false || !$this->verify($payload, $parts[1])) {
            return null;
        }
        $pos = strrpos($payload, '|');
        if ($pos === false) {
            return null;
        }
        $expires = (int) substr($payload, $pos + 1);
        if ($expires !== 0 && $expires < time()) {
            return null;
        }

        return substr($payload, 0, $pos);
    }

    /** Криптостойкая случайная строка (hex). */
    public static function token(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Хеш токена для хранения в БД (токены в открытом виде не храним). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
