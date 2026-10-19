<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;
use App\Taxonomy\CategoryRepository;
use App\Taxonomy\TagRepository;

/** Поиск по сайту и автодополнение. */
final class SearchController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly TagRepository $tags,
        private readonly CategoryRepository $categories,
        private readonly SeoService $seo,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = mb_substr($request->string('q'), 0, 120);
        $page = max(1, $request->int('page', 1));
        $result = ['items' => [], 'total' => 0];
        if (mb_strlen($query) >= 2) {
            $result = $this->articles->search($query, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        }
        $this->meta($this->seo->page($query !== '' ? 'Поиск: ' . $query : 'Поиск', 'Поиск по материалам сайта', '/search', false));

        return $this->view('web/search', [
            'query' => $query,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'popularTags' => $this->tags->popular(12),
        ]);
    }

    /** Подсказки: материалы, рубрики и темы, подходящие под введённое. */
    public function suggest(Request $request): Response
    {
        $query = mb_substr($request->string('q'), 0, 80);
        $lower = mb_strtolower($query);
        $categories = [];
        foreach ($this->categories->all() as $category) {
            if ($lower !== '' && str_contains(mb_strtolower($category->name), $lower)) {
                $categories[] = ['title' => $category->name, 'url' => '/category/' . $category->slug];
            }
        }
        $tags = [];
        if (mb_strlen($query) >= 2) {
            foreach (array_slice($this->tags->all($query, 5), 0, 4) as $tag) {
                $tags[] = ['title' => '#' . $tag->name, 'url' => '/tag/' . $tag->slug];
            }
        }

        return $this->json([
            'ok' => true,
            'articles' => $this->articles->suggest($query, 6),
            'topics' => array_slice(array_merge($categories, $tags), 0, 5),
        ])->withHeader('Cache-Control', 'private, max-age=60');
    }
}
