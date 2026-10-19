<?php

declare(strict_types=1);

namespace App\Engagement;

use App\Core\Database\Database;

final class BookmarkRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    public function has(int $userId, int $articleId): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM bookmarks WHERE user_id = :u AND article_id = :a', ['u' => $userId, 'a' => $articleId]);
    }

    /** @return bool true — теперь в закладках */
    public function toggle(int $userId, int $articleId): bool
    {
        $removed = $this->db->execute('DELETE FROM bookmarks WHERE user_id = :u AND article_id = :a', ['u' => $userId, 'a' => $articleId]);
        if ($removed > 0) {
            return false;
        }
        $this->db->execute('INSERT INTO bookmarks (user_id, article_id) VALUES (:u, :a) ON CONFLICT DO NOTHING', ['u' => $userId, 'a' => $articleId]);

        return true;
    }

    /** @return array{ids:list<int>,total:int} id статей (опубликованных) в порядке добавления */
    public function page(int $userId, int $limit, int $offset): array
    {
        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM bookmarks b JOIN articles a ON a.id = b.article_id AND a.status = 'published' WHERE b.user_id = :u",
            ['u' => $userId]
        );
        $ids = $this->db->fetchColumn(
            "SELECT b.article_id FROM bookmarks b JOIN articles a ON a.id = b.article_id AND a.status = 'published'
              WHERE b.user_id = :u ORDER BY b.created_at DESC LIMIT :l OFFSET :o",
            ['u' => $userId, 'l' => $limit, 'o' => $offset]
        );

        return ['ids' => array_map('intval', $ids), 'total' => $total];
    }

    /** @param list<int> $articleIds @return array<int,true> */
    public function marked(int $userId, array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        [$in, $params] = Database::in('a', $articleIds);
        $map = [];
        foreach ($this->db->fetchColumn("SELECT article_id FROM bookmarks WHERE user_id = :u AND article_id IN {$in}", $params + ['u' => $userId]) as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }
}
