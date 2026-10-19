<?php

declare(strict_types=1);

namespace App\Article;

use App\Core\Database\Database;

/** История правок статьи: каждая значимая правка сохраняется как снимок. */
final class RevisionRepository
{
    private const KEEP = 100;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public function add(int $articleId, ?int $userId, array $snapshot, string $note = ''): int
    {
        $id = (int) $this->db->insert('article_revisions', [
            'article_id' => $articleId,
            'user_id' => $userId,
            'data' => $snapshot,
            'note' => mb_substr($note, 0, 200),
        ]);
        $this->db->execute(
            'DELETE FROM article_revisions WHERE article_id = :a AND id NOT IN (
                SELECT id FROM article_revisions WHERE article_id = :a ORDER BY id DESC LIMIT :keep)',
            ['a' => $articleId, 'keep' => self::KEEP]
        );

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function list(int $articleId): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.note, r.created_at, r.data->>\'title\' AS title, u.name AS user_name,
                    length(r.data->>\'body\') AS body_length
               FROM article_revisions r LEFT JOIN users u ON u.id = r.user_id
              WHERE r.article_id = :a ORDER BY r.id DESC',
            ['a' => $articleId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $articleId, int $revisionId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT r.*, u.name AS user_name FROM article_revisions r LEFT JOIN users u ON u.id = r.user_id WHERE r.article_id = :a AND r.id = :id',
            ['a' => $articleId, 'id' => $revisionId]
        );
        if ($row === null) {
            return null;
        }
        $row['data'] = json_decode((string) $row['data'], true) ?: [];

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function latest(int $articleId): ?array
    {
        $row = $this->db->fetchOne('SELECT data FROM article_revisions WHERE article_id = :a ORDER BY id DESC LIMIT 1', ['a' => $articleId]);

        return $row === null ? null : (json_decode((string) $row['data'], true) ?: null);
    }
}
