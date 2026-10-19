<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database\Database;

final class OAuthIdentityRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function findUserId(string $provider, string $providerUserId): ?int
    {
        $id = $this->db->fetchValue(
            'SELECT user_id FROM oauth_identities WHERE provider = :p AND provider_user_id = :id',
            ['p' => $provider, 'id' => $providerUserId]
        );

        return $id === null ? null : (int) $id;
    }

    /** @return array<string,mixed>|null */
    public function findForUser(int $userId, string $provider): ?array
    {
        return $this->db->fetchOne('SELECT * FROM oauth_identities WHERE user_id = :u AND provider = :p', ['u' => $userId, 'p' => $provider]);
    }

    public function link(int $userId, string $provider, string $providerUserId, ?string $login, ?string $email): void
    {
        $this->db->insert('oauth_identities', [
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'login' => $login,
            'email' => $email,
        ]);
    }

    public function unlink(int $userId, string $provider): void
    {
        $this->db->delete('oauth_identities', ['user_id' => $userId, 'provider' => $provider]);
    }
}
