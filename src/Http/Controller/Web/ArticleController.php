<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Analytics\ViewTracker;
use App\Article\Article;
use App\Article\ArticleRepository;
use App\Article\ArticleStatus;
use App\Article\ArticleType;
use App\Article\GalleryRepository;
use App\Article\LiveEntryRepository;
use App\Billing\PlanRepository;
use App\Billing\SubscriptionService;
use App\Comment\CommentRepository;
use App\Comment\CommentTree;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Crypto;
use App\Engagement\AuthorFollowRepository;
use App\Engagement\BookmarkRepository;
use App\Engagement\ReactionRepository;
use App\Http\Controller;
use App\Paywall\AccessDecision;
use App\Paywall\BotDetector;
use App\Paywall\PaywallService;
use App\Paywall\VisitorResolver;
use App\Seo\SeoService;
use App\Settings\SettingsService;
use App\User\Gate;

/** Страница статьи: пейволл, реакции, закладки, комментарии, связанные материалы. */
final class ArticleController extends Controller
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly GalleryRepository $gallery,
        private readonly LiveEntryRepository $live,
        private readonly CommentRepository $comments,
        private readonly ReactionRepository $reactions,
        private readonly BookmarkRepository $bookmarks,
        private readonly AuthorFollowRepository $follows,
        private readonly PaywallService $paywall,
        private readonly VisitorResolver $visitors,
        private readonly BotDetector $bots,
        private readonly ViewTracker $views,
        private readonly SeoService $seo,
        private readonly SettingsService $settings,
        private readonly PlanRepository $plans,
        private readonly SubscriptionService $subscriptions,
        private readonly Gate $gate,
        private readonly Crypto $crypto,
    ) {
    }

    public function show(Request $request, string $slug): Response
    {
        $article = $this->articles->findPublishedBySlug($slug) ?? $this->notFound('Материал не найден или снят с публикации');

        return $this->render($request, $article, false);
    }

    /**
     * Предпросмотр непубликованного материала: сотрудникам редакции или по подписанной ссылке (?t=).
     */
    public function preview(Request $request, int $id): Response
    {
        $article = $this->articles->find($id) ?? $this->notFound();
        $token = (string) $request->query('t', '');
        $signed = $token !== '' && $this->crypto->readSignedToken($token) === 'preview:' . $article->id;
        if (!$signed && !$this->gate->canViewUnpublished($this->user(), $article)) {
            throw HttpException::forbidden('Предпросмотр доступен только редакции');
        }

        return $this->render($request, $article, true)->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Cache-Control', 'no-store');
    }

    /** Новые записи live-блога (опрашивается клиентом). */
    public function live(Request $request, int $id): Response
    {
        $article = $this->articles->find($id);
        if ($article === null || !$article->isPublished() || $article->type !== ArticleType::Liveblog) {
            $this->notFound();
        }
        $after = $request->int('after', 0);
        $entries = array_reverse($this->live->list($article->id, $after > 0 ? $after : null));
        $html = '';
        $lastId = $after;
        foreach ($entries as $entry) {
            $html .= $this->fragment('partials/live-entry', ['entry' => $entry]);
            $lastId = max($lastId, (int) $entry['id']);
        }

        return $this->json(['ok' => true, 'html' => $html, 'last_id' => $lastId, 'count' => count($entries)]);
    }

    private function render(Request $request, Article $article, bool $isPreview): Response
    {
        $user = $this->user();
        $visitor = $this->visitors->resolve($request);
        $isBot = $this->bots->isBot($request->userAgent());

        if ($isPreview) {
            $decision = new AccessDecision(AccessDecision::STAFF, true);
        } else {
            $decision = $this->paywall->evaluate($article, $user, $visitor, $isBot);
            if ($decision->isLocked()) {
                $this->paywall->recordShown($article, $visitor);
            }
            $this->views->recordView($article, $visitor, $request);
        }

        $tags = $this->articles->tagsFor([$article->id])[$article->id] ?? [];
        $manual = $this->articles->manualRelated($article->id, 4);
        $auto = $this->articles->relatedByTags($article, 4, array_map(static fn (Article $a): int => $a->id, $manual));
        $locked = $decision->isLocked();

        $liveEntries = $article->type === ArticleType::Liveblog ? $this->live->list($article->id) : [];
        $commentTree = !$locked && $article->allowComments
            ? CommentTree::build($this->comments->forArticle($article->id, $user?->id))
            : [];

        $this->meta($this->seo->article($article, $tags, $locked)->noindexIf($isPreview));

        $cheapest = $this->cheapestPlan();
        $response = $this->view('web/article/show', [
            'article' => $article,
            'tags' => $tags,
            'decision' => $decision,
            'locked' => $locked,
            'bodyHtml' => $locked ? $this->paywall->preview($article) : $article->body,
            'isPreview' => $isPreview,
            'gallery' => $article->type === ArticleType::Photo ? $this->gallery->forArticle($article->id) : [],
            'liveEntries' => $liveEntries,
            'liveLastId' => $liveEntries === [] ? 0 : max(array_map(static fn (array $e): int => (int) $e['id'], $liveEntries)),
            'related' => $manual,
            'moreLikeThis' => $auto,
            'reactionCounts' => $this->reactions->counts($article->id),
            'myReaction' => $user !== null ? $this->reactions->userReaction($article->id, $user->id) : null,
            'bookmarked' => $user !== null && $this->bookmarks->has($user->id, $article->id),
            'comments' => $commentTree,
            'canComment' => $article->allowComments && !$locked,
            'following' => $user !== null && $article->authorId !== null && $this->follows->isFollowing($user->id, $article->authorId),
            'cheapestPlan' => $cheapest,
            'canTrial' => $user !== null && $cheapest !== null && $this->subscriptions->canStartTrial($user, $cheapest),
            'paywallTitle' => $this->settings->get('paywall.title'),
            'paywallText' => $this->settings->get('paywall.text'),
            'paywallCta' => $this->settings->get('paywall.cta'),
            'counterText' => strtr($this->settings->get('paywall.counter_text'), [
                '{left}' => (string) $decision->remaining, '{limit}' => (string) $decision->limit,
            ]),
            'shareUrl' => $this->seo->absolute($article->url()),
        ]);
        $this->visitors->attach($response, $visitor, $request);
        if ($locked || $decision->reason === AccessDecision::METERED) {
            $response->withHeader('Cache-Control', 'private, no-store');
        }

        return $response;
    }

    private function cheapestPlan(): ?\App\Billing\Plan
    {
        $plans = $this->plans->all(true);

        return $plans === [] ? null : array_reduce($plans, static fn (?\App\Billing\Plan $c, \App\Billing\Plan $p): \App\Billing\Plan => $c === null || $p->monthlyPrice() < $c->monthlyPrice() ? $p : $c);
    }
}
