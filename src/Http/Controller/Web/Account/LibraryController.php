<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Article\ArticleRepository;
use App\Comment\CommentRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Engagement\BookmarkRepository;
use App\Engagement\ReadingHistoryRepository;
use App\Http\Controller;
use App\Seo\SeoService;

/** Закладки, история чтения, мои комментарии. */
final class LibraryController extends Controller
{
    private const PER_PAGE = 12;

    public function __construct(
        private readonly BookmarkRepository $bookmarks,
        private readonly ReadingHistoryRepository $history,
        private readonly ArticleRepository $articles,
        private readonly CommentRepository $comments,
        private readonly SeoService $seo,
    ) {
    }

    public function bookmarks(Request $request): Response
    {
        $user = $this->requireUser();
        $page = max(1, $request->int('page', 1));
        $result = $this->bookmarks->page($user->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $this->meta($this->seo->page('Закладки', '', '/account/bookmarks', false));

        return $this->view('web/account/bookmarks', [
            'section' => 'bookmarks',
            'items' => $this->articles->cardsByIds($result['ids']),
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
        ]);
    }

    public function history(Request $request): Response
    {
        $user = $this->requireUser();
        $page = max(1, $request->int('page', 1));
        $result = $this->history->page($user->id, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $articles = $this->articles->cardsByIds(array_column($result['items'], 'article_id'));
        $progress = [];
        foreach ($result['items'] as $item) {
            $progress[$item['article_id']] = $item;
        }
        $this->meta($this->seo->page('История чтения', '', '/account/history', false));

        return $this->view('web/account/history', [
            'section' => 'history',
            'items' => $articles,
            'progress' => $progress,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'total' => $result['total'],
        ]);
    }

    public function clearHistory(): Response
    {
        $this->history->clear($this->requireUser()->id);
        $this->flash('success', 'История чтения очищена');

        return $this->redirect('/account/history');
    }

    public function comments(Request $request): Response
    {
        $user = $this->requireUser();
        $page = max(1, $request->int('page', 1));
        $result = $this->comments->forUser($user->id, 20, ($page - 1) * 20);
        $this->meta($this->seo->page('Мои комментарии', '', '/account/comments', false));

        return $this->view('web/account/comments', [
            'section' => 'comments',
            'items' => $result['items'],
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / 20)),
            'total' => $result['total'],
        ]);
    }
}
