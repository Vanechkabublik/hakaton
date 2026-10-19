<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Article\ArticleType;
use App\Article\FeedCriteria;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;
use App\Taxonomy\CategoryRepository;
use App\Taxonomy\TagRepository;

/** Главная, рубрики, теги и типы материалов: лента с подгрузкой при прокрутке. */
final class HomeController extends Controller
{
    public const PAGE_SIZE = 12;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly SeoService $seo,
    ) {
    }

    public function index(Request $request): Response
    {
        $criteria = $this->criteria($request);
        $cursor = $this->cursor($request);
        $filtered = $criteria->toQuery() !== [];

        $featured = (!$filtered && $cursor === null) ? $this->articles->featured(3) : [];
        $criteria = new FeedCriteria($criteria->categorySlug, $criteria->tagSlugs, $criteria->type, null, array_map(static fn ($a): int => $a->id, $featured));
        $feed = $this->articles->feed($criteria, self::PAGE_SIZE, $cursor);

        $meta = $this->seo->home();
        if ($filtered || $cursor !== null) {
            $meta->noindex();
            $meta->canonical = $this->seo->absolute('/');
        }
        $this->meta($meta);

        return $this->view('web/home', $this->sidebar() + [
            'heading' => null,
            'featured' => $featured,
            'items' => $feed['items'],
            'next' => $feed['next'],
            'criteria' => $criteria,
            'activeCategory' => $criteria->categorySlug,
            'activeTags' => $criteria->tagSlugs,
            'baseUrl' => '/',
        ]);
    }

    public function category(Request $request, string $slug): Response
    {
        $category = $this->categories->findBySlug($slug) ?? $this->notFound('Рубрика не найдена');
        $cursor = $this->cursor($request);
        $criteria = new FeedCriteria($category->slug, $this->tagSlugs($request));
        $feed = $this->articles->feed($criteria, self::PAGE_SIZE, $cursor);

        $meta = $this->seo->category($category);
        if ($cursor !== null || $criteria->tagSlugs !== []) {
            $meta->noindex();
            $meta->canonical = $this->seo->absolute('/category/' . $category->slug);
        }
        $this->meta($meta);

        return $this->view('web/home', $this->sidebar() + [
            'heading' => ['title' => $category->name, 'text' => $category->description, 'color' => $category->color],
            'featured' => [],
            'items' => $feed['items'],
            'next' => $feed['next'],
            'criteria' => $criteria,
            'activeCategory' => $category->slug,
            'activeTags' => $criteria->tagSlugs,
            'baseUrl' => '/category/' . $category->slug,
        ]);
    }

    public function tag(Request $request, string $slug): Response
    {
        $tag = $this->tags->findBySlug($slug) ?? $this->notFound('Тема не найдена');
        $feed = $this->articles->feed(new FeedCriteria(null, [$tag->slug]), self::PAGE_SIZE, $this->cursor($request));
        $criteria = new FeedCriteria(null, [$tag->slug]);
        $meta = $this->seo->tag($tag);
        if ($this->cursor($request) !== null) {
            $meta->noindex();
        }
        $this->meta($meta);

        return $this->view('web/home', $this->sidebar() + [
            'heading' => ['title' => '#' . $tag->name, 'text' => 'Материалы по теме', 'color' => null],
            'featured' => [],
            'items' => $feed['items'],
            'next' => $feed['next'],
            'criteria' => $criteria,
            'activeCategory' => null,
            'activeTags' => [],
            'baseUrl' => '/tag/' . $tag->slug,
        ]);
    }

    public function type(Request $request, string $type): Response
    {
        $kind = ArticleType::tryFrom($type) ?? $this->notFound();
        $criteria = new FeedCriteria(null, [], $kind);
        $feed = $this->articles->feed($criteria, self::PAGE_SIZE, $this->cursor($request));
        $this->meta($this->seo->page($kind->label(), 'Все материалы формата «' . $kind->label() . '»', '/type/' . $kind->value));

        return $this->view('web/home', $this->sidebar() + [
            'heading' => ['title' => $kind->label(), 'text' => '', 'color' => null],
            'featured' => [],
            'items' => $feed['items'],
            'next' => $feed['next'],
            'criteria' => $criteria,
            'activeCategory' => null,
            'activeTags' => [],
            'baseUrl' => '/type/' . $kind->value,
        ]);
    }

    /** @return array<string,mixed> */
    private function sidebar(): array
    {
        return [
            'popular' => $this->articles->popular(5),
            'popularTags' => $this->tags->popular(14),
        ];
    }

    private function criteria(Request $request): FeedCriteria
    {
        $category = $request->query('category');
        $type = ArticleType::tryFrom((string) $request->query('type', ''));

        return new FeedCriteria(
            is_string($category) && preg_match('/^[a-z0-9-]+$/', $category) ? $category : null,
            $this->tagSlugs($request),
            $type,
        );
    }

    /** @return list<string> */
    private function tagSlugs(Request $request): array
    {
        $raw = $request->query('tags');
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        return array_slice(array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $s): bool => (bool) preg_match('/^[a-z0-9-]{1,100}$/', $s)
        )), 0, 5);
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) && preg_match('/^\d{10,}_\d+$/', $cursor) ? $cursor : null;
    }
}
