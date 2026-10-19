<?php

declare(strict_types=1);

namespace App\Article;

use App\Core\Database\Database;
use App\Core\Database\Repository;

/** @extends Repository<Article> */
final class ArticleRepository extends Repository
{
    protected const TABLE = 'articles';
    protected const ENTITY = Article::class;

    /** Столбцы без search_vector (тяжёлый и в моделях не нужен). */
    private const COLUMNS = 'a.id, a.type, a.status, a.access, a.slug, a.title, a.subtitle, a.lead, a.cover_media_id, a.cover_caption,
        a.author_id, a.category_id, a.reading_minutes, a.seo_title, a.seo_description, a.is_featured, a.allow_comments, a.is_live,
        a.publish_at, a.published_at, a.archived_at, a.submitted_at, a.moderated_by, a.moderation_note, a.views_count,
        a.comments_count, a.created_at, a.updated_at';

    private const JOINS = 'FROM articles a
        LEFT JOIN users u ON u.id = a.author_id
        LEFT JOIN categories c ON c.id = a.category_id
        LEFT JOIN media m ON m.id = a.cover_media_id';

    private const RELATED_COLUMNS = 'u.name AS author_name, u.slug AS author_slug, u.avatar_path AS author_avatar,
        c.name AS category_name, c.slug AS category_slug, c.color AS category_color,
        m.variants AS cover_variants, m.alt AS cover_alt, m.width AS cover_width, m.height AS cover_height';

    private const PUBLISHED = "a.status = 'published' AND a.published_at <= now()";

    /** Полная статья по id (с телом). */
    public function find(int $id): ?Article
    {
        return $this->one('WHERE a.id = :id', ['id' => $id], true);
    }

    public function findBySlug(string $slug): ?Article
    {
        return $this->one('WHERE a.slug = :slug', ['slug' => $slug], true);
    }

    public function findPublishedBySlug(string $slug): ?Article
    {
        return $this->one('WHERE a.slug = :slug AND ' . self::PUBLISHED, ['slug' => $slug], true);
    }

    /**
     * @param array<string,mixed> $params
     */
    private function one(string $where, array $params, bool $withBody): ?Article
    {
        $body = $withBody ? 'a.body' : 'left(a.body, 600) AS body';
        $row = $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ", {$body}, " . self::RELATED_COLUMNS . ' ' . self::JOINS . " {$where} LIMIT 1",
            $params
        );

        return $row === null ? null : Article::fromRow($row);
    }

    /**
     * Карточки по списку id в заданном порядке.
     *
     * @param list<int> $ids
     * @return list<Article>
     */
    public function cardsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = Database::in('id', $ids);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 600) AS body, ' . self::RELATED_COLUMNS . ' ' . self::JOINS . " WHERE a.id IN {$in}",
            $params
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = Article::fromRow($row);
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * Лента опубликованных материалов с keyset-пагинацией.
     *
     * @return array{items:list<Article>,next:?string}
     */
    public function feed(FeedCriteria $criteria, int $limit, ?string $cursor = null): array
    {
        [$where, $params] = $this->feedWhere($criteria);
        if ($cursor !== null && preg_match('/^(\d{10,})_(\d+)$/', $cursor, $m)) {
            $where[] = '(floor(extract(epoch FROM a.published_at) * 1000000)::bigint, a.id) < (:cur_ts, :cur_id)';
            $params['cur_ts'] = (int) $m[1];
            $params['cur_id'] = (int) $m[2];
        }
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 600) AS body, floor(extract(epoch FROM a.published_at) * 1000000)::bigint AS cursor_ts, '
            . self::RELATED_COLUMNS . ' ' . self::JOINS
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.published_at DESC, a.id DESC LIMIT :limit',
            $params + ['limit' => $limit + 1]
        );
        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = $last['cursor_ts'] . '_' . $last['id'];
        }

        return ['items' => Article::fromRows($rows), 'next' => $next];
    }

    /**
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function feedWhere(FeedCriteria $criteria): array
    {
        $where = [self::PUBLISHED];
        $params = [];
        if ($criteria->categorySlug !== null) {
            $where[] = 'c.slug = :cat';
            $params['cat'] = $criteria->categorySlug;
        }
        if ($criteria->tagSlugs !== []) {
            [$in, $tagParams] = Database::in('tag', $criteria->tagSlugs);
            $where[] = "EXISTS (SELECT 1 FROM article_tag at JOIN tags t ON t.id = at.tag_id WHERE at.article_id = a.id AND t.slug IN {$in})";
            $params += $tagParams;
        }
        if ($criteria->type !== null) {
            $where[] = 'a.type = :type';
            $params['type'] = $criteria->type->value;
        }
        if ($criteria->authorId !== null) {
            $where[] = 'a.author_id = :author';
            $params['author'] = $criteria->authorId;
        }
        if ($criteria->excludeIds !== []) {
            [$in, $exParams] = Database::in('ex', $criteria->excludeIds);
            $where[] = "a.id NOT IN {$in}";
            $params += $exParams;
        }
        if ($criteria->onlyFree) {
            $where[] = "a.access = 'free'";
        }

        return [$where, $params];
    }

    /** Закреплённые материалы для главной. */
    public function featured(int $limit = 3): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 600) AS body, ' . self::RELATED_COLUMNS . ' ' . self::JOINS
            . ' WHERE ' . self::PUBLISHED . ' AND a.is_featured ORDER BY a.published_at DESC LIMIT :l',
            ['l' => $limit]
        );

        return Article::fromRows($rows);
    }

    /** Популярное за последние дни по просмотрам. */
    public function popular(int $limit = 5, int $days = 7): array
    {
        $ids = $this->db->fetchColumn(
            "SELECT v.article_id FROM article_views v JOIN articles a ON a.id = v.article_id AND " . self::PUBLISHED . '
              WHERE v.created_at > now() - make_interval(days => :d)
              GROUP BY v.article_id ORDER BY COUNT(*) DESC, MAX(a.published_at) DESC LIMIT :l',
            ['d' => $days, 'l' => $limit]
        );
        if (count($ids) < $limit) {
            $more = $this->db->fetchColumn(
                'SELECT a.id FROM articles a WHERE ' . self::PUBLISHED . ' ORDER BY a.views_count DESC, a.published_at DESC LIMIT :l',
                ['l' => $limit * 2]
            );
            $ids = array_values(array_unique(array_merge($ids, $more)));
        }

        return $this->cardsByIds(array_map('intval', array_slice($ids, 0, $limit)));
    }

    /**
     * Блок «Читайте также»: вручную выбранные редакцией материалы.
     *
     * @return list<Article>
     */
    public function manualRelated(int $articleId, int $limit = 4): array
    {
        $ids = $this->db->fetchColumn(
            'SELECT r.related_id FROM article_related r JOIN articles a ON a.id = r.related_id AND ' . self::PUBLISHED . '
              WHERE r.article_id = :id ORDER BY r.sort_order, a.published_at DESC LIMIT :l',
            ['id' => $articleId, 'l' => $limit]
        );

        return $this->cardsByIds(array_map('intval', $ids));
    }

    /**
     * Связанные материалы: общие теги и рубрика, свежие выше.
     *
     * @param list<int> $excludeIds
     * @return list<Article>
     */
    public function relatedByTags(Article $article, int $limit = 4, array $excludeIds = []): array
    {
        $exclude = array_values(array_unique([...$excludeIds, $article->id]));
        [$in, $params] = Database::in('ex', $exclude);
        $ids = $this->db->fetchColumn(
            "SELECT a.id
               FROM articles a
               LEFT JOIN (SELECT at2.article_id, COUNT(*) AS shared
                            FROM article_tag at2
                           WHERE at2.tag_id IN (SELECT tag_id FROM article_tag WHERE article_id = :id)
                           GROUP BY at2.article_id) s ON s.article_id = a.id
              WHERE " . self::PUBLISHED . " AND a.id NOT IN {$in}
                AND (s.shared IS NOT NULL OR (:cat::int IS NOT NULL AND a.category_id = :cat))
              ORDER BY COALESCE(s.shared, 0) DESC, (a.category_id IS NOT DISTINCT FROM :cat::int) DESC, a.published_at DESC
              LIMIT :l",
            $params + ['id' => $article->id, 'cat' => $article->categoryId, 'l' => $limit]
        );

        return $this->cardsByIds(array_map('intval', $ids));
    }

    // ---- поиск ----------------------------------------------------------------------------

    /**
     * Полнотекстовый поиск (PostgreSQL FTS, словарь russian).
     *
     * @return array{items:list<Article>,total:int}
     */
    public function search(string $query, int $limit, int $offset): array
    {
        $params = ['q' => $query];
        $total = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM articles a WHERE " . self::PUBLISHED . " AND a.search_vector @@ websearch_to_tsquery('russian', :q)",
            $params
        );
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }
        $rows = $this->db->fetchAll(
            "SELECT a.id, ts_rank_cd(a.search_vector, websearch_to_tsquery('russian', :q)) AS rank
               FROM articles a
              WHERE " . self::PUBLISHED . " AND a.search_vector @@ websearch_to_tsquery('russian', :q)
              ORDER BY rank DESC, a.published_at DESC LIMIT :l OFFSET :o",
            $params + ['l' => $limit, 'o' => $offset]
        );
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        $items = $this->cardsByIds($ids);

        // Фрагменты с подсветкой (маркеры заменяются на <mark> после экранирования)
        [$in, $inParams] = Database::in('hid', $ids);
        $headlines = $this->db->fetchAll(
            "SELECT id, ts_headline('russian',
                        coalesce(nullif(lead, ''), '') || ' ' || regexp_replace(body, '<[^>]+>', ' ', 'g'),
                        websearch_to_tsquery('russian', :q),
                        'StartSel=[[[, StopSel=]]], MaxFragments=2, MinWords=8, MaxWords=24, FragmentDelimiter= … ') AS h
               FROM articles WHERE id IN {$in}",
            $inParams + ['q' => $query]
        );
        $map = [];
        foreach ($headlines as $row) {
            $text = htmlspecialchars((string) $row['h'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $map[(int) $row['id']] = str_replace(['[[[', ']]]'], ['<mark>', '</mark>'], $text);
        }
        $rankById = [];
        foreach ($rows as $row) {
            $rankById[(int) $row['id']] = (float) $row['rank'];
        }
        foreach ($items as $item) {
            $item->headline = $map[$item->id] ?? null;
            $item->rank = $rankById[$item->id] ?? 0.0;
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Подсказки для автодополнения: заголовки статей (по подстроке и по FTS-префиксу).
     *
     * @return list<array{title:string,url:string,category:?string}>
     */
    public function suggest(string $query, int $limit = 6): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tsQuery = implode(' & ', array_map(static fn (string $w): string => $w . ':*', array_slice($words, 0, 5)));
        $rows = $this->db->fetchAll(
            "SELECT a.slug, a.title, c.name AS category_name
               FROM articles a LEFT JOIN categories c ON c.id = a.category_id
              WHERE " . self::PUBLISHED . "
                AND (a.title ILIKE :like OR (:ts <> '' AND a.search_vector @@ to_tsquery('russian', :ts)))
              ORDER BY (a.title ILIKE :starts) DESC, a.published_at DESC
              LIMIT :l",
            [
                'like' => '%' . Database::likeEscape($query) . '%',
                'starts' => Database::likeEscape($query) . '%',
                'ts' => $tsQuery,
                'l' => $limit,
            ]
        );

        return array_map(static fn (array $r): array => [
            'title' => (string) $r['title'],
            'url' => '/news/' . $r['slug'],
            'category' => $r['category_name'] !== null ? (string) $r['category_name'] : null,
        ], $rows);
    }

    // ---- запись ---------------------------------------------------------------------------

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM articles WHERE slug = :s AND (:id::bigint IS NULL OR id <> :id)',
            ['s' => $slug, 'id' => $exceptId]
        );
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $data['updated_at'] = new \DateTimeImmutable();
        $this->db->update('articles', $data, ['id' => $id]);
    }

    /** @param list<int> $tagIds */
    public function syncTags(int $articleId, array $tagIds): void
    {
        $this->db->transaction(function () use ($articleId, $tagIds): void {
            $this->db->delete('article_tag', ['article_id' => $articleId]);
            foreach (array_unique($tagIds) as $tagId) {
                $this->db->execute(
                    'INSERT INTO article_tag (article_id, tag_id) VALUES (:a, :t) ON CONFLICT DO NOTHING',
                    ['a' => $articleId, 't' => $tagId]
                );
            }
        });
    }

    /** @param list<int> $relatedIds */
    public function syncRelated(int $articleId, array $relatedIds): void
    {
        $this->db->transaction(function () use ($articleId, $relatedIds): void {
            $this->db->delete('article_related', ['article_id' => $articleId]);
            $order = 0;
            foreach (array_unique($relatedIds) as $relatedId) {
                if ($relatedId === $articleId) {
                    continue;
                }
                $this->db->execute(
                    'INSERT INTO article_related (article_id, related_id, sort_order)
                     SELECT :a, id, :o FROM articles WHERE id = :r ON CONFLICT DO NOTHING',
                    ['a' => $articleId, 'r' => $relatedId, 'o' => $order++]
                );
            }
        });
    }

    /** @return list<array{id:int,title:string}> */
    public function relatedChoices(int $articleId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, a.title FROM article_related r JOIN articles a ON a.id = r.related_id WHERE r.article_id = :id ORDER BY r.sort_order',
            ['id' => $articleId]
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title']], $rows);
    }

    /** Подбор материалов для поля «Связанные» в редакторе. */
    public function lookup(string $query, ?int $exceptId, int $limit = 10): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, title, status FROM articles
              WHERE title ILIKE :q AND (:ex::bigint IS NULL OR id <> :ex) ORDER BY updated_at DESC LIMIT :l",
            ['q' => '%' . Database::likeEscape(trim($query)) . '%', 'ex' => $exceptId, 'l' => $limit]
        );

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'status' => (string) $r['status']], $rows);
    }

    public function incrementViews(int $id): void
    {
        $this->db->execute('UPDATE articles SET views_count = views_count + 1 WHERE id = :id', ['id' => $id]);
    }

    public function refreshCommentsCount(int $id): void
    {
        $this->db->execute(
            "UPDATE articles SET comments_count = (SELECT COUNT(*) FROM comments WHERE article_id = :id AND status = 'approved') WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * Теги для набора статей.
     *
     * @param list<int> $articleIds
     * @return array<int,list<array{id:int,slug:string,name:string}>>
     */
    public function tagsFor(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        [$in, $params] = Database::in('a', $articleIds);
        $map = [];
        foreach ($this->db->fetchAll(
            "SELECT at.article_id, t.id, t.slug, t.name FROM article_tag at JOIN tags t ON t.id = at.tag_id WHERE at.article_id IN {$in} ORDER BY t.name",
            $params
        ) as $row) {
            $map[(int) $row['article_id']][] = ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name']];
        }

        return $map;
    }

    // ---- планировщик ----------------------------------------------------------------------

    /**
     * Опубликовать запланированные материалы, время которых пришло.
     *
     * @return list<int> id опубликованных
     */
    public function publishDue(): array
    {
        $rows = $this->db->fetchAll(
            "UPDATE articles SET status = 'published', published_at = COALESCE(publish_at, now()), updated_at = now()
              WHERE status = 'scheduled' AND publish_at IS NOT NULL AND publish_at <= now()
              RETURNING id"
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    // ---- админка --------------------------------------------------------------------------

    /**
     * @param array{q?:string,status?:string,type?:string,access?:string,category?:int|string,author?:int|string} $filters
     * @return array{items:list<Article>,total:int}
     */
    public function adminSearch(array $filters, int $limit, int $offset, ?int $onlyAuthorId = null): array
    {
        $where = ['TRUE'];
        $params = [];
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $where[] = '(a.title ILIKE :q OR a.slug ILIKE :q)';
            $params['q'] = '%' . Database::likeEscape($q) . '%';
        }
        if (!empty($filters['status']) && ArticleStatus::tryFrom((string) $filters['status']) !== null) {
            $where[] = 'a.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['type']) && ArticleType::tryFrom((string) $filters['type']) !== null) {
            $where[] = 'a.type = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['access']) && AccessLevel::tryFrom((string) $filters['access']) !== null) {
            $where[] = 'a.access = :access';
            $params['access'] = $filters['access'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'a.category_id = :cat';
            $params['cat'] = (int) $filters['category'];
        }
        if (!empty($filters['author'])) {
            $where[] = 'a.author_id = :author';
            $params['author'] = (int) $filters['author'];
        }
        if ($onlyAuthorId !== null) {
            $where[] = 'a.author_id = :only_author';
            $params['only_author'] = $onlyAuthorId;
        }
        $sqlWhere = 'WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM articles a {$sqlWhere}", $params);
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 200) AS body, ' . self::RELATED_COLUMNS . ' ' . self::JOINS
            . " {$sqlWhere} ORDER BY a.updated_at DESC, a.id DESC LIMIT :limit OFFSET :offset",
            $params + ['limit' => $limit, 'offset' => $offset]
        );

        return ['items' => Article::fromRows($rows), 'total' => $total];
    }

    /** @return array<string,int> статус => количество */
    public function countByStatus(?int $onlyAuthorId = null): array
    {
        $rows = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS c FROM articles WHERE (:a::bigint IS NULL OR author_id = :a) GROUP BY status',
            ['a' => $onlyAuthorId]
        );
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['status']] = (int) $row['c'];
        }

        return $result;
    }

    /** @return list<array{slug:string,updated:string}> для sitemap */
    public function sitemapEntries(int $limit = 50000): array
    {
        return $this->db->fetchAll(
            "SELECT a.slug, COALESCE(a.updated_at, a.published_at) AS updated FROM articles a
              WHERE " . self::PUBLISHED . ' ORDER BY a.published_at DESC LIMIT :l',
            ['l' => $limit]
        );
    }

    /** Свежие материалы для RSS. */
    public function latestForFeed(int $limit = 30, ?string $categorySlug = null): array
    {
        $params = ['l' => $limit];
        $extra = '';
        if ($categorySlug !== null) {
            $extra = ' AND c.slug = :cat';
            $params['cat'] = $categorySlug;
        }
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 600) AS body, ' . self::RELATED_COLUMNS . ' ' . self::JOINS
            . ' WHERE ' . self::PUBLISHED . $extra . ' ORDER BY a.published_at DESC LIMIT :l',
            $params
        );

        return Article::fromRows($rows);
    }

    /**
     * Свежие материалы за период (дайджест).
     *
     * @return list<Article>
     */
    public function publishedSince(\DateTimeInterface $since, int $limit = 10): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ', left(a.body, 600) AS body, ' . self::RELATED_COLUMNS . ' ' . self::JOINS
            . ' WHERE ' . self::PUBLISHED . ' AND a.published_at > :since ORDER BY a.views_count DESC, a.published_at DESC LIMIT :l',
            ['since' => $since, 'l' => $limit]
        );

        return Article::fromRows($rows);
    }
}
