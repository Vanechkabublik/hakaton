<?php

declare(strict_types=1);

namespace App\Page;

use App\Core\Database\Repository;
use App\Support\HtmlSanitizer;

/** @extends Repository<Page> */
final class PageRepository extends Repository
{
    protected const TABLE = 'pages';
    protected const ENTITY = Page::class;

    public function find(int $id): ?Page
    {
        $p = parent::find($id);

        return $p instanceof Page ? $p : null;
    }

    public function findBySlug(string $slug, bool $onlyPublished = true): ?Page
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM pages WHERE slug = :s' . ($onlyPublished ? ' AND is_published' : ''),
            ['s' => $slug]
        );

        return $row === null ? null : Page::fromRow($row);
    }

    /** @return list<Page> */
    public function all(): array
    {
        return Page::fromRows($this->db->fetchAll('SELECT * FROM pages ORDER BY id'));
    }

    /** @param array<string,mixed> $data */
    public function savePage(int $id, array $data, HtmlSanitizer $sanitizer): void
    {
        $this->db->update('pages', [
            'title' => mb_substr(trim((string) $data['title']), 0, 200),
            'body' => $sanitizer->clean((string) ($data['body'] ?? '')),
            'seo_description' => mb_substr(trim(strip_tags((string) ($data['seo_description'] ?? ''))), 0, 400),
            'is_published' => !empty($data['is_published']),
            'updated_at' => new \DateTimeImmutable(),
        ], ['id' => $id]);
    }
}
