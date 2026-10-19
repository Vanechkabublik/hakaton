<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Core\Security\Crypto;

/**
 * «Запомнить меня»: cookie selector:validator. В БД лежит хеш validator,
 * при каждом использовании токен ротируется; кража старого cookie не даёт повторного входа.
 */
final class RememberMeService
{
    public const COOKIE = 'remember';
    public const TTL_DAYS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    /** Выдать новый токен; возвращает значение cookie. */
    public function issue(int $userId, Request $request): string
    {
        $selector = Crypto::token(12);
        $validator = Crypto::token(32);
        $this->db->insert('remember_tokens', [
            'user_id' => $userId,
            'selector' => $selector,
            'validator_hash' => Crypto::hashToken($validator),
            'user_agent' => $request->userAgent(),
            'ip' => $request->ip(),
            'expires_at' => new \DateTimeImmutable('+' . self::TTL_DAYS . ' days'),
        ], 'id');

        return $selector . ':' . $validator;
    }

    /**
     * Проверить cookie и израсходовать токен (ротация).
     *
     * @return int|null id пользователя
     */
    public function consume(string $cookie): ?int
    {
        $parts = explode(':', $cookie, 2);
        if (count($parts) !== 2 || !preg_match('/^[a-f0-9]{24}$/', $parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
            return null;
        }
        $row = $this->db->fetchOne(
            'DELETE FROM remember_tokens WHERE selector = :s AND expires_at > now() RETURNING user_id, validator_hash',
            ['s' => $parts[0]]
        );
        if ($row === null) {
            return null;
        }
        if (!hash_equals((string) $row['validator_hash'], Crypto::hashToken($parts[1]))) {
            // совпал selector, но не validator — похоже на кражу: гасим все токены пользователя
            $this->forgetAll((int) $row['user_id']);

            return null;
        }

        return (int) $row['user_id'];
    }

    public function forget(string $cookie): void
    {
        $selector = explode(':', $cookie, 2)[0];
        $this->db->execute('DELETE FROM remember_tokens WHERE selector = :s', ['s' => $selector]);
    }

    public function forgetAll(int $userId): void
    {
        $this->db->execute('DELETE FROM remember_tokens WHERE user_id = :u', ['u' => $userId]);
    }

    public function purgeExpired(): int
    {
        return $this->db->execute('DELETE FROM remember_tokens WHERE expires_at < now()');
    }
}
