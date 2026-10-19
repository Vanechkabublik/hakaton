<?php

declare(strict_types=1);

namespace App\Taxonomy;

use App\Core\Database\Database;
use App\Core\Database\Repository;
use App\Support\Slug;

/** @extends Repository<Tag> */
final class TagRepository extends Repository
{
    protected const TABLE = 'tags';
    protected const ENTITY = Tag::class;

    public function find(int $id): ?Tag
    {
        $t = parent::find($id);

        return $t instanceof Tag ? $t : null;
    }

    public function findBySlug(string $slug): ?Tag
    {
        $row = $this->db->fetchOne('SELECT * FROM tags WHERE slug = :s', ['s' => $slug]);

        return $row === null ? null : Tag::fromRow($row);
    }

    /** @param list<string> $slugs @return list<Tag> */
    public function findBySlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        [$in, $params] = Database::in('s', array_values($slugs));

        return Tag::fromRows($this->db->fetchAll("SELECT * FROM tags WHERE slug IN {$in}", $params));
    }

    /** @return list<Tag> */
    public function popular(int $limit = 15): array
    {
        return Tag::fromRows($this->db->fetchAll(
            "SELECT t.*, COUNT(a.id) AS articles_count
               FROM tags t JOIN article_tag at ON at.tag_id = t.id
               JOIN articles a ON a.id = at.article_id AND a.status = 'published'
              GROUP BY t.id ORDER BY articles_count DESC, t.name LIMIT :l",
            ['l' => $limit]
        ));
    }

    /** @return list<Tag> */
    public function all(?string $q = null, int $limit = 500): array
    {
        $where = '';
        $params = ['l' => $limit];
        if ($q !== null && trim($q) !== '') {
            $where = 'WHERE t.name ILIKE :q';
            $params['q'] = '%' . Database::likeEscape(trim($q)) . '%';
        }

        return Tag::fromRows($this->db->fetchAll(
            "SELECT t.*, (SELECT COUNT(*) FROM article_tag at WHERE at.tag_id = t.id) AS articles_count
               FROM tags t {$where} ORDER BY t.name LIMIT :l",
            $params
        ));
    }

    /** @return list<Tag> */
    public function forArticle(int $articleId): array
    {
        return Tag::fromRows($this->db->fetchAll(
            'SELECT t.* FROM tags t JOIN article_tag at ON at.tag_id = t.id WHERE at.article_id = :a ORDER BY t.name',
            ['a' => $articleId]
        ));
    }

    /**
     * Найти или создать теги по названиям.
     *
     * @param list<string> $names
     * @return list<int> id тегов
     */
    public function resolveNames(array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
            if ($name === '' || mb_strlen($name) > 60) {
                continue;
            }
            $slug = Slug::make($name, 60);
            if ($slug === '') {
                continue;
            }
            $id = $this->db->fetchValue('SELECT id FROM tags WHERE slug = :s', ['s' => $slug]);
            if ($id === null) {
                $id = $this->db->fetchValue(
                    'INSERT INTO tags (slug, name) VALUES (:s, :n) ON CONFLICT (slug) DO UPDATE SET slug = EXCLUDED.slug RETURNING id',
                    ['s' => $slug, 'n' => $name]
                );
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /** @param array{name:string,slug?:string} $data */
    public function save(?int $id, array $data): int
    {
        $slug = trim($data['slug'] ?? '') !== '' ? Slug::make($data['slug']) : Slug::make($data['name']);
        $slug = Slug::unique($slug, fn (string $s): bool => (bool) $this->db->fetchValue(
            'SELECT 1 FROM tags WHERE slug = :s AND (:id::int IS NULL OR id <> :id)',
            ['s' => $s, 'id' => $id]
        ), 'tag');
        $row = ['name' => trim($data['name']), 'slug' => $slug];
        if ($id === null) {
            return $this->create($row);
        }
        $this->updateById($id, $row);

        return $id;
    }

    /** Склеить теги: все материалы $fromId переходят в $intoId. */
    public function merge(int $fromId, int $intoId): void
    {
        if ($fromId === $intoId) {
            return;
        }
        $this->db->transaction(function () use ($fromId, $intoId): void {
            $this->db->execute(
                'INSERT INTO article_tag (article_id, tag_id) SELECT article_id, :into FROM article_tag WHERE tag_id = :from ON CONFLICT DO NOTHING',
                ['into' => $intoId, 'from' => $fromId]
            );
            $this->deleteById($fromId);
        });
    }
}
