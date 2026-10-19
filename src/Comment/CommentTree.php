<?php

declare(strict_types=1);

namespace App\Comment;

/** Собирает плоский список комментариев в дерево ответов. */
final class CommentTree
{
    public const MAX_DEPTH = 5;

    /**
     * @param list<Comment> $comments упорядочены по дате создания
     * @return list<Comment> корневые комментарии с заполненными children
     */
    public static function build(array $comments): array
    {
        $byId = [];
        foreach ($comments as $comment) {
            $comment->children = [];
            $byId[$comment->id] = $comment;
        }
        $roots = [];
        foreach ($comments as $comment) {
            $parent = $comment->parentId !== null ? ($byId[$comment->parentId] ?? null) : null;
            if ($parent === null) {
                $comment->depth = 0;
                $roots[] = $comment;
                continue;
            }
            $comment->depth = $parent->depth + 1;
            $parent->children[] = $comment;
        }

        return $roots;
    }

    /** Глубина узла-родителя, чтобы новые ответы не уходили глубже MAX_DEPTH. */
    public static function clampParent(?Comment $parent, CommentRepository $comments): ?int
    {
        if ($parent === null) {
            return null;
        }
        $depth = 0;
        $node = $parent;
        while ($node->parentId !== null && $depth < 50) {
            $next = $comments->find($node->parentId);
            if ($next === null) {
                break;
            }
            $node = $next;
            ++$depth;
        }
        if ($depth + 1 >= self::MAX_DEPTH) {
            return $parent->parentId ?? $parent->id; // выше не углубляем: отвечаем «рядом» с родителем
        }

        return $parent->id;
    }
}
