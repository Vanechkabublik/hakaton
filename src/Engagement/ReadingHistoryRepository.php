<?php

declare(strict_types=1);

namespace App\Engagement;

use App\Core\Database\Database;

final class ReadingHistoryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{items:list<array{article_id:int,last_read_at:string,progress:int,completed:bool}>,total:int} */
    public function page(int $userId, int $limit, int $offset): array
    {
        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM reading_history h JOIN articles a ON a.id = h.article_id AND a.status = 'published' WHERE h.user_id = :u",
            ['u' => $userId]
        );
        $rows = $this->db->fetchAll(
            "SELECT h.article_id, h.last_read_at, h.progress, h.completed
               FROM reading_history h JOIN articles a ON a.id = h.article_id AND a.status = 'published'
              WHERE h.user_id = :u ORDER BY h.last_read_at DESC LIMIT :l OFFSET :o",
            ['u' => $userId, 'l' => $limit, 'o' => $offset]
        );
        $items = array_map(static fn (array $r): array => [
            'article_id' => (int) $r['article_id'],
            'last_read_at' => (string) $r['last_read_at'],
            'progress' => (int) $r['progress'],
            'completed' => (bool) $r['completed'],
        ], $rows);

        return ['items' => $items, 'total' => $total];
    }

    public function clear(int $userId): void
    {
        $this->db->execute('DELETE FROM reading_history WHERE user_id = :u', ['u' => $userId]);
    }
}
