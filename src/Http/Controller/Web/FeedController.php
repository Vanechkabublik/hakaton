<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Article\ArticleType;
use App\Article\FeedCriteria;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;

/** JSON-API ленты для подгрузки при прокрутке и фильтрации без перезагрузки страницы. */
final class FeedController extends Controller
{
    public function __construct(private readonly ArticleRepository $articles)
    {
    }

    public function feed(Request $request): Response
    {
        $category = $request->query('category');
        $tags = is_string($request->query('tags')) ? array_filter(array_map('trim', explode(',', (string) $request->query('tags'))), static fn (string $t): bool => (bool) preg_match('/^[a-z0-9-]{1,100}$/', $t)) : [];
        $author = $request->query('author');
        $cursor = $request->query('cursor');
        $criteria = new FeedCriteria(
            is_string($category) && preg_match('/^[a-z0-9-]+$/', $category) ? $category : null,
            array_slice(array_values($tags), 0, 5),
            ArticleType::tryFrom((string) $request->query('type', '')),
            is_numeric($author) ? (int) $author : null,
            array_map('intval', array_filter(explode(',', (string) $request->query('exclude', '')), 'is_numeric')),
        );
        $feed = $this->articles->feed($criteria, HomeController::PAGE_SIZE, is_string($cursor) && preg_match('/^\d{10,}_\d+$/', $cursor) ? $cursor : null);

        $html = '';
        foreach ($feed['items'] as $article) {
            $html .= $this->fragment('partials/article-card', ['article' => $article, 'variant' => 'default']);
        }

        return $this->json(['ok' => true, 'html' => $html, 'next' => $feed['next'], 'count' => count($feed['items'])])
            ->withHeader('Cache-Control', 'private, max-age=30');
    }
}
