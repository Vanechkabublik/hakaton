<?php

declare(strict_types=1);

namespace App\Media;

use App\Core\Database\Database;
use App\Core\Database\Repository;

/** @extends Repository<Media> */
final class MediaRepository extends Repository
{
    protected const TABLE = 'media';
    protected const ENTITY = Media::class;

    public function find(int $id): ?Media
    {
        $m = parent::find($id);

        return $m instanceof Media ? $m : null;
    }

    /** @param list<int> $ids @return array<int,Media> */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Database::in('id', array_values(array_unique($ids)));
        $result = [];
        foreach ($this->db->fetchAll("SELECT * FROM media WHERE id IN {$in}", $params) as $row) {
            $m = Media::fromRow($row);
            $result[$m->id] = $m;
        }

        return $result;
    }

    /**
     * @return array{items:list<Media>,total:int}
     */
    public function search(?string $q, int $limit, int $offset): array
    {
        $where = '';
        $params = [];
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE original_name ILIKE :q OR alt ILIKE :q';
            $params['q'] = '%' . Database::likeEscape(trim($q)) . '%';
        }
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM media {$where}", $params);
        $rows = $this->db->fetchAll("SELECT * FROM media {$where} ORDER BY id DESC LIMIT :l OFFSET :o", $params + ['l' => $limit, 'o' => $offset]);

        return ['items' => Media::fromRows($rows), 'total' => $total];
    }

    /** Где используется файл: обложки и фотографии галерей. */
    public function usageCount(int $mediaId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT (SELECT COUNT(*) FROM articles WHERE cover_media_id = :id) + (SELECT COUNT(*) FROM article_gallery WHERE media_id = :id)',
            ['id' => $mediaId]
        );
    }

    public function totalSize(): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(SUM(size_bytes), 0) FROM media');
    }
}
