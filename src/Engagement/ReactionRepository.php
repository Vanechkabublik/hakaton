<?php

declare(strict_types=1);

namespace App\Engagement;

use App\Core\Database\Database;

/** Реакции на статьи: «нравится», «полезно», «спорно». Один пользователь — одна реакция на статью. */
final class ReactionRepository
{
    public const KINDS = [
        'like' => 'Нравится',
        'useful' => 'Полезно',
        'controversial' => 'Спорно',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string,int> */
    public function counts(int $articleId): array
    {
        $counts = array_fill_keys(array_keys(self::KINDS), 0);
        foreach ($this->db->fetchAll('SELECT kind, COUNT(*) AS c FROM reactions WHERE article_id = :a GROUP BY kind', ['a' => $articleId]) as $row) {
            $counts[(string) $row['kind']] = (int) $row['c'];
        }

        return $counts;
    }

    public function userReaction(int $articleId, int $userId): ?string
    {
        $kind = $this->db->fetchValue('SELECT kind FROM reactions WHERE article_id = :a AND user_id = :u', ['a' => $articleId, 'u' => $userId]);

        return $kind === null ? null : (string) $kind;
    }

    /** Поставить реакцию; повторный выбор той же реакции снимает её. Возвращает текущую реакцию или null. */
    public function toggle(int $articleId, int $userId, string $kind): ?string
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Неизвестная реакция');
        }
        $current = $this->userReaction($articleId, $userId);
        if ($current === $kind) {
            $this->db->execute('DELETE FROM reactions WHERE article_id = :a AND user_id = :u', ['a' => $articleId, 'u' => $userId]);

            return null;
        }
        $this->db->execute(
            'INSERT INTO reactions (article_id, user_id, kind) VALUES (:a, :u, :k)
             ON CONFLICT (article_id, user_id) DO UPDATE SET kind = EXCLUDED.kind, created_at = now()',
            ['a' => $articleId, 'u' => $userId, 'k' => $kind]
        );

        return $kind;
    }
}
