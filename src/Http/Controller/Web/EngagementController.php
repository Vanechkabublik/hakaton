<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Engagement\BookmarkRepository;
use App\Engagement\ReactionRepository;
use App\Http\Controller;

/** Реакции на статьи и закладки. */
final class EngagementController extends Controller
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ReactionRepository $reactions,
        private readonly BookmarkRepository $bookmarks,
    ) {
    }

    public function react(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->articles->find($id);
        if ($article === null || !$article->isPublished()) {
            $this->notFound();
        }
        $kind = $request->string('kind');
        if (!isset(ReactionRepository::KINDS[$kind])) {
            throw new \DomainException('Неизвестная реакция');
        }
        $mine = $this->reactions->toggle($article->id, $user->id, $kind);

        return $this->json(['ok' => true, 'mine' => $mine, 'counts' => $this->reactions->counts($article->id)]);
    }

    public function bookmark(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->articles->find($id);
        if ($article === null || !$article->isPublished()) {
            $this->notFound();
        }

        return $this->json(['ok' => true, 'bookmarked' => $this->bookmarks->toggle($user->id, $article->id)]);
    }
}
