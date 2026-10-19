<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database\Database;
use App\Core\Security\Crypto;
use App\User\User;

/** Одноразовые токены из писем: подтверждение email, сброс пароля, смена email. В БД — только SHA-256 токена. */
final class EmailTokenService
{
    public const VERIFY = 'verify';
    public const RESET = 'reset';
    public const EMAIL_CHANGE = 'email_change';

    private const TTL = [
        self::VERIFY => 172800,   // 48 часов
        self::RESET => 3600,      // 1 час
        self::EMAIL_CHANGE => 86400,
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Создать токен (предыдущие неиспользованные того же типа аннулируются). Возвращает токен в открытом виде.
     *
     * @param array<string,mixed> $payload
     */
    public function create(User $user, string $type, array $payload = [], ?int $ttlSeconds = null): string
    {
        $this->db->execute('DELETE FROM email_tokens WHERE user_id = :u AND type = :t AND used_at IS NULL', ['u' => $user->id, 't' => $type]);
        $token = Crypto::token(32);
        $this->db->insert('email_tokens', [
            'user_id' => $user->id,
            'type' => $type,
            'token_hash' => Crypto::hashToken($token),
            'payload' => $payload,
            'expires_at' => new \DateTimeImmutable('+' . ($ttlSeconds ?? self::TTL[$type]) . ' seconds'),
        ]);

        return $token;
    }

    /**
     * Проверить токен, не расходуя его.
     *
     * @return array{user_id:int,payload:array<string,mixed>}|null
     */
    public function peek(string $token, string $type): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT user_id, payload FROM email_tokens WHERE token_hash = :h AND type = :t AND used_at IS NULL AND expires_at > now()',
            ['h' => Crypto::hashToken($token), 't' => $type]
        );

        return $row === null ? null : ['user_id' => (int) $row['user_id'], 'payload' => $this->payload($row['payload'])];
    }

    /**
     * Атомарно израсходовать токен.
     *
     * @return array{user_id:int,payload:array<string,mixed>}|null
     */
    public function consume(string $token, string $type): ?array
    {
        $row = $this->db->fetchOne(
            'UPDATE email_tokens SET used_at = now()
              WHERE token_hash = :h AND type = :t AND used_at IS NULL AND expires_at > now()
              RETURNING user_id, payload',
            ['h' => Crypto::hashToken($token), 't' => $type]
        );

        return $row === null ? null : ['user_id' => (int) $row['user_id'], 'payload' => $this->payload($row['payload'])];
    }

    public function purgeExpired(): int
    {
        return $this->db->execute("DELETE FROM email_tokens WHERE expires_at < now() - interval '7 days' OR used_at < now() - interval '7 days'");
    }

    /** @return array<string,mixed> */
    private function payload(mixed $raw): array
    {
        $data = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($data) ? $data : [];
    }
}
