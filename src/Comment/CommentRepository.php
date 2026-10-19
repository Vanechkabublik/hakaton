<?php

declare(strict_types=1);

namespace App\Comment;

use App\Core\Database\Database;
use App\Core\Database\Repository;

/** @extends Repository<Comment> */
final class CommentRepository extends Repository
{
    protected const TABLE = 'comments';
    protected const ENTITY = Comment::class;

    private const SELECT = 'SELECT c.*, u.name AS author_name, u.avatar_path AS author_avatar, u.role AS author_role, u.slug AS author_slug,
                                   a.title AS article_title, a.slug AS article_slug
                              FROM comments c
                              LEFT JOIN users u ON u.id = c.user_id
                              JOIN articles a ON a.id = c.article_id';

    public function find(int $id): ?Comment
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);

        return $row === null ? null : Comment::fromRow($row);
    }

    /**
     * Видимые комментарии статьи: одобренные + собственные комментарии зрителя на модерации.
     *
     * @return list<Comment>
     */
    public function forArticle(int $articleId, ?int $viewerId, int $limit = 500): array
    {
        $comments = Comment::fromRows($this->db->fetchAll(
            self::SELECT . " WHERE c.article_id = :a AND (c.status = 'approved' OR (c.status = 'pending' AND c.user_id IS NOT NULL AND c.user_id = :v))
              ORDER BY c.created_at, c.id LIMIT :l",
            ['a' => $articleId, 'v' => $viewerId, 'l' => $limit]
        ));
        if ($viewerId !== null && $comments !== []) {
            $liked = $this->likedIds($viewerId, array_map(static fn (Comment $c): int => $c->id, $comments));
            foreach ($comments as $comment) {
                $comment->likedByMe = isset($liked[$comment->id]);
            }
        }

        return $comments;
    }

    /** @param list<int> $commentIds @return array<int,true> */
    public function likedIds(int $userId, array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }
        [$in, $params] = Database::in('c', $commentIds);
        $map = [];
        foreach ($this->db->fetchColumn("SELECT comment_id FROM comment_likes WHERE user_id = :u AND comment_id IN {$in}", $params + ['u' => $userId]) as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('comments', $data, ['id' => $id]);
    }

    public function toggleLike(int $commentId, int $userId): bool
    {
        return $this->db->transaction(function () use ($commentId, $userId): bool {
            $removed = $this->db->execute('DELETE FROM comment_likes WHERE comment_id = :c AND user_id = :u', ['c' => $commentId, 'u' => $userId]);
            if ($removed === 0) {
                $this->db->execute('INSERT INTO comment_likes (comment_id, user_id) VALUES (:c, :u) ON CONFLICT DO NOTHING', ['c' => $commentId, 'u' => $userId]);
            }
            $this->db->execute(
                'UPDATE comments SET likes_count = (SELECT COUNT(*) FROM comment_likes WHERE comment_id = :c) WHERE id = :c',
                ['c' => $commentId]
            );

            return $removed === 0;
        });
    }

    /** Добавляет жалобу; false, если пользователь уже жаловался. */
    public function addReport(int $commentId, int $userId, string $reason, string $details): bool
    {
        return $this->db->transaction(function () use ($commentId, $userId, $reason, $details): bool {
            $inserted = $this->db->execute(
                'INSERT INTO comment_reports (comment_id, user_id, reason, details) VALUES (:c, :u, :r, :d) ON CONFLICT DO NOTHING',
                ['c' => $commentId, 'u' => $userId, 'r' => $reason, 'd' => mb_substr($details, 0, 500)]
            );
            if ($inserted === 1) {
                $this->db->execute(
                    'UPDATE comments SET reports_count = (SELECT COUNT(*) FROM comment_reports WHERE comment_id = :c AND resolved_at IS NULL) WHERE id = :c',
                    ['c' => $commentId]
                );
            }

            return $inserted === 1;
        });
    }

    public function resolveReports(int $commentId): void
    {
        $this->db->execute('UPDATE comment_reports SET resolved_at = now() WHERE comment_id = :c AND resolved_at IS NULL', ['c' => $commentId]);
        $this->db->execute('UPDATE comments SET reports_count = 0 WHERE id = :c', ['c' => $commentId]);
    }

    /** @return list<array<string,mixed>> */
    public function reportsFor(int $commentId): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, u.name AS user_name FROM comment_reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.comment_id = :c ORDER BY r.id DESC',
            ['c' => $commentId]
        );
    }

    public function recentDuplicate(int $userId, int $articleId, string $body): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM comments WHERE user_id = :u AND article_id = :a AND body = :b AND created_at > now() - interval '5 minutes' LIMIT 1",
            ['u' => $userId, 'a' => $articleId, 'b' => $body]
        );
    }

    public function countApprovedByUser(int $userId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM comments WHERE user_id = :u AND status = 'approved'", ['u' => $userId]);
    }

    /**
     * Комментарии пользователя для личного кабинета.
     *
     * @return array{items:list<Comment>,total:int}
     */
    public function forUser(int $userId, int $limit, int $offset): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM comments WHERE user_id = :u', ['u' => $userId]);
        $rows = $this->db->fetchAll(
            self::SELECT . ' WHERE c.user_id = :u ORDER BY c.created_at DESC LIMIT :l OFFSET :o',
            ['u' => $userId, 'l' => $limit, 'o' => $offset]
        );

        return ['items' => Comment::fromRows($rows), 'total' => $total];
    }

    /**
     * Очередь модерации.
     *
     * @param array{status?:string,q?:string,reported?:bool} $filters
     * @return array{items:list<Comment>,total:int}
     */
    public function adminSearch(array $filters, int $limit, int $offset): array
    {
        $where = ['TRUE'];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ['pending', 'approved', 'rejected', 'spam'], true)) {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        if (!empty($filters['reported'])) {
            $where[] = 'c.reports_count > 0';
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $where[] = '(c.body ILIKE :q OR u.email ILIKE :q OR u.name ILIKE :q OR a.title ILIKE :q)';
            $params['q'] = '%' . Database::likeEscape($q) . '%';
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM comments c LEFT JOIN users u ON u.id = c.user_id JOIN articles a ON a.id = c.article_id WHERE {$sqlWhere}",
            $params
        );
        $rows = $this->db->fetchAll(
            self::SELECT . " WHERE {$sqlWhere} ORDER BY (c.reports_count > 0) DESC, c.created_at DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => Comment::fromRows($rows), 'total' => $total];
    }

    /** @return array<string,int> */
    public function countByStatus(): array
    {
        $result = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'spam' => 0];
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS c FROM comments GROUP BY status') as $row) {
            $result[(string) $row['status']] = (int) $row['c'];
        }

        return $result;
    }

    /** @param list<int> $ids */
    public function bulkSetStatus(array $ids, string $status, int $moderatorId): int
    {
        if ($ids === []) {
            return 0;
        }
        [$in, $params] = Database::in('i', $ids);

        return $this->db->execute(
            "UPDATE comments SET status = :s, moderated_by = :m, moderated_at = now() WHERE id IN {$in}",
            $params + ['s' => $status, 'm' => $moderatorId]
        );
    }

    /** @param list<int> $ids @return list<int> id статей, у которых изменились комментарии */
    public function articleIdsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Database::in('i', $ids);

        return array_map('intval', $this->db->fetchColumn("SELECT DISTINCT article_id FROM comments WHERE id IN {$in}", $params));
    }
}
