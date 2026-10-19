<?php

declare(strict_types=1);

namespace App\Engagement;

use App\Core\Database\Database;

/** Подписки читателей на авторов («новости от любимых авторов»). */
final class AuthorFollowRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function isFollowing(int $userId, int $authorId): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM author_follows WHERE user_id = :u AND author_id = :a', ['u' => $userId, 'a' => $authorId]);
    }

    /** @return bool true — теперь подписан */
    public function toggle(int $userId, int $authorId): bool
    {
        if ($userId === $authorId) {
            return false;
        }
        $removed = $this->db->execute('DELETE FROM author_follows WHERE user_id = :u AND author_id = :a', ['u' => $userId, 'a' => $authorId]);
        if ($removed > 0) {
            return false;
        }
        $this->db->execute('INSERT INTO author_follows (user_id, author_id) VALUES (:u, :a) ON CONFLICT DO NOTHING', ['u' => $userId, 'a' => $authorId]);

        return true;
    }

    public function followersCount(int $authorId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM author_follows WHERE author_id = :a', ['a' => $authorId]);
    }

    /** @return list<array{id:int,name:string,slug:?string}> */
    public function followedAuthors(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT u.id, u.name, u.slug FROM author_follows f JOIN users u ON u.id = f.author_id WHERE f.user_id = :u ORDER BY u.name',
            ['u' => $userId]
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'slug' => $r['slug'] !== null ? (string) $r['slug'] : null], $rows);
    }

    /**
     * Подписчики автора, которым нужно сообщить о новой статье.
     *
     * @return list<int> id пользователей
     */
    public function followerIdsToNotify(int $authorId): array
    {
        return array_map('intval', $this->db->fetchColumn(
            "SELECT u.id FROM author_follows f JOIN users u ON u.id = f.user_id
              WHERE f.author_id = :a AND u.status = 'active' AND u.email_verified_at IS NOT NULL AND (u.notify_authors OR u.notify_push)",
            ['a' => $authorId]
        ));
    }
}
