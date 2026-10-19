<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Article\FeedCriteria;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Engagement\AuthorFollowRepository;
use App\Http\Controller;
use App\Seo\SeoService;
use App\User\UserRepository;

final class AuthorController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ArticleRepository $articles,
        private readonly AuthorFollowRepository $follows,
        private readonly SeoService $seo,
    ) {
    }

    public function index(): Response
    {
        $this->meta($this->seo->page('Авторы', 'Журналисты и эксперты издания', '/authors'));

        return $this->view('web/authors/index', ['authors' => $this->users->listAuthors()]);
    }

    public function show(Request $request, string $slug): Response
    {
        $author = $this->users->findBySlug($slug);
        if ($author === null || !$author->isStaff()) {
            $this->notFound('Автор не найден');
        }
        $cursor = $request->query('cursor');
        $criteria = new FeedCriteria(null, [], null, $author->id);
        $feed = $this->articles->feed($criteria, HomeController::PAGE_SIZE, is_string($cursor) && preg_match('/^\d{10,}_\d+$/', $cursor) ? $cursor : null);
        $meta = $this->seo->author($author);
        if (is_string($cursor)) {
            $meta->noindex();
        }
        $this->meta($meta);
        $user = $this->user();

        return $this->view('web/authors/show', [
            'author' => $author,
            'items' => $feed['items'],
            'next' => $feed['next'],
            'followers' => $this->follows->followersCount($author->id),
            'following' => $user !== null && $this->follows->isFollowing($user->id, $author->id),
        ]);
    }

    public function follow(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $author = $this->users->find($id);
        if ($author === null || !$author->isStaff()) {
            $this->notFound();
        }
        if ($author->id === $user->id) {
            throw new \DomainException('Нельзя подписаться на самого себя');
        }
        $following = $this->follows->toggle($user->id, $author->id);

        return $this->json(['ok' => true, 'following' => $following, 'followers' => $this->follows->followersCount($author->id)]);
    }
}
