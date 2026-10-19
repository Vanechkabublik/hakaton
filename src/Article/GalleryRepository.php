<?php

declare(strict_types=1);

namespace App\Article;

use App\Core\Database\Database;
use App\Media\Media;

/** Фотографии фоторепортажа. */
final class GalleryRepository
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array{media:Media,caption:string}> */
    public function forArticle(int $articleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT g.caption, m.* FROM article_gallery g JOIN media m ON m.id = g.media_id WHERE g.article_id = :a ORDER BY g.sort_order, g.id',
            ['a' => $articleId]
        );

        return array_map(static fn (array $row): array => ['media' => Media::fromRow($row), 'caption' => (string) $row['caption']], $rows);
    }

    /** @param list<array{media_id:int,caption:string}> $items */
    public function replace(int $articleId, array $items): void
    {
        $this->db->transaction(function () use ($articleId, $items): void {
            $this->db->delete('article_gallery', ['article_id' => $articleId]);
            foreach (array_values($items) as $i => $item) {
                $this->db->execute(
                    'INSERT INTO article_gallery (article_id, media_id, caption, sort_order)
                     SELECT :a, id, :c, :o FROM media WHERE id = :m',
                    ['a' => $articleId, 'm' => (int) $item['media_id'], 'c' => mb_substr((string) $item['caption'], 0, 500), 'o' => $i]
                );
            }
        });
    }
}
