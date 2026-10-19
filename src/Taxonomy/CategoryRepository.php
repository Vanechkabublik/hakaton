<?php

declare(strict_types=1);

namespace App\Taxonomy;

use App\Core\Database\Repository;
use App\Support\Slug;

/** @extends Repository<Category> */
final class CategoryRepository extends Repository
{
    protected const TABLE = 'categories';
    protected const ENTITY = Category::class;

    public function find(int $id): ?Category
    {
        $c = parent::find($id);

        return $c instanceof Category ? $c : null;
    }

    public function findBySlug(string $slug): ?Category
    {
        $row = $this->db->fetchOne('SELECT * FROM categories WHERE slug = :s', ['s' => $slug]);

        return $row === null ? null : Category::fromRow($row);
    }

    /** @return list<Category> */
    public function all(bool $withCounts = false): array
    {
        $sql = $withCounts
            ? "SELECT c.*, (SELECT COUNT(*) FROM articles a WHERE a.category_id = c.id AND a.status = 'published') AS articles_count
                 FROM categories c ORDER BY c.sort_order, c.name"
            : 'SELECT * FROM categories ORDER BY sort_order, name';

        return Category::fromRows($this->db->fetchAll($sql));
    }

    /** @param array{name:string,slug?:string,description?:string,color?:string,sort_order?:int} $data */
    public function save(?int $id, array $data): int
    {
        $slug = trim($data['slug'] ?? '') !== '' ? Slug::make($data['slug']) : Slug::make($data['name']);
        $slug = Slug::unique($slug, fn (string $s): bool => (bool) $this->db->fetchValue(
            'SELECT 1 FROM categories WHERE slug = :s AND (:id::int IS NULL OR id <> :id)',
            ['s' => $s, 'id' => $id]
        ), 'category');
        $row = [
            'name' => trim($data['name']),
            'slug' => $slug,
            'description' => trim($data['description'] ?? ''),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($data['color'] ?? '')) ? $data['color'] : '#2563eb',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        if ($id === null) {
            return $this->create($row);
        }
        $this->updateById($id, $row);

        return $id;
    }
}
