<?php

declare(strict_types=1);

namespace App\Article;

use App\Core\Database\Database;

/** Записи live-блога. */
final class LiveEntryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Записи в обратном хронологическом порядке; $afterId — только новее указанной записи.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $articleId, ?int $afterId = null, int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.title, e.body, e.is_pinned, e.posted_at, u.name AS author_name
               FROM live_entries e LEFT JOIN users u ON u.id = e.user_id
              WHERE e.article_id = :a AND (:after::bigint IS NULL OR e.id > :after)
              ORDER BY e.is_pinned DESC, e.posted_at DESC, e.id DESC LIMIT :l',
            ['a' => $articleId, 'after' => $afterId, 'l' => $limit]
        );
    }

    public function add(int $articleId, ?int $userId, string $title, string $body, bool $pinned = false): int
    {
        return (int) $this->db->insert('live_entries', [
            'article_id' => $articleId,
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'is_pinned' => $pinned,
        ]);
    }

    public function update(int $id, int $articleId, string $title, string $body, bool $pinned): void
    {
        $this->db->update('live_entries', ['title' => $title, 'body' => $body, 'is_pinned' => $pinned], ['id' => $id, 'article_id' => $articleId]);
    }

    public function delete(int $id, int $articleId): void
    {
        $this->db->delete('live_entries', ['id' => $id, 'article_id' => $articleId]);
    }

    public function lastId(int $articleId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM live_entries WHERE article_id = :a', ['a' => $articleId]);
    }
}
