<?php
/** @var \App\Core\View\View $this */
/** @var \App\Article\Article $article */
/** @var list<array{id:int,slug:string,name:string}> $tags */
/** @var \App\Paywall\AccessDecision $decision */
/** @var bool $locked */
/** @var string $bodyHtml */
/** @var bool $isPreview */
/** @var list<array{media:\App\Media\Media,caption:string}> $gallery */
/** @var list<array<string,mixed>> $liveEntries */
/** @var list<\App\Article\Article> $related */
/** @var list<\App\Article\Article> $moreLikeThis */
/** @var array<string,int> $reactionCounts */
/** @var list<\App\Comment\Comment> $comments */
$this->layout('layouts/web', ['bodyClass' => 'page-article']);
$isLive = $article->type === \App\Article\ArticleType::Liveblog;
$totalComments = $article->commentsCount;
$shareTitle = rawurlencode($article->title);
$shareUrlEnc = rawurlencode($shareUrl);
?>
<div class="read-progress" id="read-progress" aria-hidden="true"><div class="read-progress-bar" id="read-progress-bar"></div></div>

<article class="article" id="article" data-article-id="<?= (int) $article->id ?>" data-locked="<?= $locked ? '1' : '0' ?>"
         data-live="<?= $isLive && $article->isLive ? '1' : '0' ?>" data-live-last="<?= (int) $liveLastId ?>" data-track="<?= $isPreview ? '0' : '1' ?>">

    <?php if ($isPreview): ?>
        <div class="preview-banner" role="note"><div class="container">Предпросмотр: материал в статусе «<?= $this->e($article->status->label()) ?>». Читатели его не видят.</div></div>
    <?php endif; ?>

    <header class="article-header container container-narrow">
        <nav class="breadcrumbs" aria-label="Хлебные крошки">
            <a href="/">Главная</a>
            <?php if ($article->categorySlug): ?><span aria-hidden="true">›</span><a href="/category/<?= $this->e($article->categorySlug) ?>"><?= $this->e((string) $article->categoryName) ?></a><?php endif; ?>
        </nav>
        <div class="card-kicker">
            <?php if ($article->categoryName): ?><a class="chip" href="/category/<?= $this->e((string) $article->categorySlug) ?>" style="--chip: <?= $this->e((string) $article->categoryColor) ?>"><?= $this->e($article->categoryName) ?></a><?php endif; ?>
            <?php if ($article->type !== \App\Article\ArticleType::News): ?><a class="type-badge" href="/type/<?= $this->e($article->type->value) ?>"><?= $this->e($article->type->label()) ?></a><?php endif; ?>
            <?php if ($article->isPaid()): ?><span class="lock-badge"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z" fill="currentColor"/></svg><?= $article->access === \App\Article\AccessLevel::Premium ? 'Premium' : 'Подписка' ?></span><?php endif; ?>
            <?php if ($isLive && $article->isLive): ?><span class="live-badge"><span class="live-dot"></span>В эфире</span><?php endif; ?>
        </div>
        <h1 class="article-title"><?= $this->e($article->title) ?></h1>
        <?php if ($article->subtitle !== ''): ?><p class="article-subtitle"><?= $this->e($article->subtitle) ?></p><?php endif; ?>

        <div class="article-meta">
            <?php if ($article->authorName): ?>
                <a class="author-chip" href="<?= $article->authorSlug ? '/author/' . $this->e($article->authorSlug) : '#' ?>">
                    <?php if ($article->authorAvatar): ?>
                        <img class="avatar" src="<?= $this->e(str_starts_with($article->authorAvatar, 'http') ? $article->authorAvatar : '/uploads/' . $article->authorAvatar) ?>" alt="" width="36" height="36">
                    <?php else: ?>
                        <span class="avatar avatar-text"><?= $this->e(mb_strtoupper(mb_substr($article->authorName, 0, 1))) ?></span>
                    <?php endif; ?>
                    <span><?= $this->e($article->authorName) ?></span>
                </a>
            <?php endif; ?>
            <time datetime="<?= $this->e($this->iso($article->displayDate())) ?>"><?= $this->e($this->dateTime($article->displayDate())) ?></time>
            <span><?= \App\Support\Format::readingTime($article->readingMinutes) ?></span>
            <span><?= $this->number($article->viewsCount) ?> <?= $this->plural($article->viewsCount, ['просмотр', 'просмотра', 'просмотров']) ?></span>
        </div>

        <div class="article-tools" role="group" aria-label="Действия">
            <?php if ($user): ?>
                <button type="button" class="tool-btn<?= $bookmarked ? ' is-active' : '' ?>" id="bookmark-btn" aria-pressed="<?= $bookmarked ? 'true' : 'false' ?>">
                    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1Z" fill="currentColor"/></svg>
                    <span data-label><?= $bookmarked ? 'Сохранено' : 'Сохранить' ?></span>
                </button>
            <?php else: ?>
                <a class="tool-btn" href="/login" title="Войдите, чтобы сохранять статьи"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4-7 4V4a1 1 0 0 1 1-1Z" fill="currentColor"/></svg><span>Сохранить</span></a>
            <?php endif; ?>
            <div class="share" id="share" data-url="<?= $this->e($shareUrl) ?>" data-title="<?= $this->e($article->title) ?>">
                <span class="muted small">Поделиться:</span>
                <a class="tool-btn" target="_blank" rel="noopener" href="https://t.me/share/url?url=<?= $shareUrlEnc ?>&amp;text=<?= $shareTitle ?>">Telegram</a>
                <a class="tool-btn" target="_blank" rel="noopener" href="https://vk.com/share.php?url=<?= $shareUrlEnc ?>&amp;title=<?= $shareTitle ?>">ВКонтакте</a>
                <a class="tool-btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= $shareTitle ?>%20<?= $shareUrlEnc ?>">WhatsApp</a>
                <button type="button" class="tool-btn" id="copy-link">Скопировать ссылку</button>
                <button type="button" class="tool-btn" id="native-share" hidden>Ещё…</button>
            </div>
        </div>
    </header>

    <?php if ($article->hasCover()): ?>
        <figure class="article-cover container container-wide">
            <img src="<?= $this->e((string) $article->coverUrl(1280)) ?>" srcset="<?= $this->e($article->coverSrcset()) ?>" sizes="(min-width: 1180px) 1100px, 100vw"
                 alt="<?= $this->e((string) ($article->coverAlt ?: $article->title)) ?>" width="1280" height="720" fetchpriority="high">
            <?php if ($article->coverCaption !== ''): ?><figcaption><?= $this->e($article->coverCaption) ?></figcaption><?php endif; ?>
        </figure>
    <?php endif; ?>

    <div class="container container-narrow">
        <?php if ($article->lead !== ''): ?><p class="article-lead"><?= $this->e($article->lead) ?></p><?php endif; ?>

        <?php if ($decision->showCounter()): ?>
            <aside class="meter-banner" role="note">
                <span><?= $this->e($counterText) ?></span>
                <a href="/subscribe?from=<?= (int) $article->id ?>">Подписаться</a>
            </aside>
        <?php endif; ?>

        <div class="article-body prose<?= $locked ? ' is-preview' : '' ?>" id="article-body"><?= $bodyHtml ?></div>

        <?php if ($locked): ?>
            <section class="paywall" id="paywall" aria-labelledby="paywall-title">
                <div class="paywall-fade" aria-hidden="true"></div>
                <div class="paywall-card">
                    <span class="lock-badge"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z" fill="currentColor"/></svg><?= $decision->needsPremium ? 'Только для Premium' : 'Для подписчиков' ?></span>
                    <h2 id="paywall-title"><?= $this->e($paywallTitle) ?></h2>
                    <p><?= $this->e($decision->needsPremium
                        ? 'Этот материал входит в тариф Premium. Перейдите на Premium, чтобы читать эксклюзивные аналитику и лонгриды.'
                        : $paywallText) ?></p>
                    <?php if ($decision->limit > 0 && !$decision->needsPremium): ?>
                        <p class="muted small">Бесплатный лимит на этот месяц (<?= (int) $decision->limit ?>) исчерпан — он обновится в начале следующего.</p>
                    <?php endif; ?>
                    <div class="paywall-actions">
                        <a class="btn btn-primary btn-lg" href="/subscribe?from=<?= (int) $article->id ?>"><?= $this->e($decision->needsPremium ? 'Перейти на Premium' : $paywallCta) ?></a>
                        <?php if ($canTrial && $cheapestPlan): ?>
                            <form action="/subscribe/trial/<?= (int) $cheapestPlan->id ?>" method="post" class="inline-form"><?= $this->csrfField() ?>
                                <button type="submit" class="btn btn-ghost btn-lg">Попробовать <?= (int) $cheapestPlan->trialDays ?> дней бесплатно</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($cheapestPlan): ?><p class="muted small">Подписка от <?= $this->money($cheapestPlan->monthlyPrice()) ?> в месяц. Отменить можно в любой момент.</p><?php endif; ?>
                    <?php if (!$user): ?><p class="small">Уже подписаны? <a href="/login">Войдите</a>.</p><?php endif; ?>
                </div>
            </section>
        <?php else: ?>

            <?php if ($article->type === \App\Article\ArticleType::Photo && $gallery !== []): ?>
                <section class="gallery" aria-label="Фотографии">
                    <?php foreach ($gallery as $i => $item): $m = $item['media']; ?>
                        <figure class="gallery-item">
                            <a href="<?= $this->e($m->url(1920)) ?>" data-lightbox="<?= $i ?>" data-caption="<?= $this->e($item['caption']) ?>">
                                <img src="<?= $this->e($m->url(960)) ?>" srcset="<?= $this->e($m->srcset()) ?>" sizes="(min-width: 900px) 700px, 100vw" alt="<?= $this->e($m->alt !== '' ? $m->alt : $item['caption']) ?>" loading="lazy" width="<?= (int) $m->width ?>" height="<?= (int) $m->height ?>">
                            </a>
                            <?php if ($item['caption'] !== ''): ?><figcaption><?= $this->e($item['caption']) ?></figcaption><?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </section>
            <?php endif; ?>

            <?php if ($isLive): ?>
                <section class="live-feed" aria-label="Хроника событий">
                    <h2>Хроника</h2>
                    <div id="live-entries" aria-live="polite">
                        <?php foreach ($liveEntries as $entry): ?><?= $this->partial('partials/live-entry', ['entry' => $entry]) ?><?php endforeach; ?>
                    </div>
                    <?php if ($liveEntries === []): ?><p class="muted" id="live-empty">Записей пока нет — следите за обновлениями.</p><?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($tags !== []): ?>
                <ul class="tag-list" aria-label="Темы">
                    <?php foreach ($tags as $tag): ?><li><a class="chip-filter chip-tag" href="/tag/<?= $this->e($tag['slug']) ?>">#<?= $this->e($tag['name']) ?></a></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <section class="reactions" id="reactions" aria-label="Реакции">
                <p class="reactions-title">Как вам материал?</p>
                <div class="reaction-buttons">
                    <?php foreach (\App\Engagement\ReactionRepository::KINDS as $kind => $label): ?>
                        <button type="button" class="reaction-btn<?= $myReaction === $kind ? ' is-active' : '' ?>" data-kind="<?= $this->e($kind) ?>" aria-pressed="<?= $myReaction === $kind ? 'true' : 'false' ?>"<?= $user ? '' : ' data-guest="1"' ?>>
                            <span class="reaction-icon" aria-hidden="true"><?= ['like' => '👍', 'useful' => '💡', 'controversial' => '🤔'][$kind] ?></span>
                            <span><?= $this->e($label) ?></span>
                            <span class="reaction-count" data-count><?= (int) ($reactionCounts[$kind] ?? 0) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <?php if (!$user): ?><p class="muted small"><a href="/login">Войдите</a>, чтобы оставить реакцию.</p><?php endif; ?>
            </section>

            <?php if ($article->authorName && $article->authorSlug): ?>
                <section class="author-box">
                    <?php if ($article->authorAvatar): ?>
                        <img class="avatar avatar-lg" src="<?= $this->e(str_starts_with($article->authorAvatar, 'http') ? $article->authorAvatar : '/uploads/' . $article->authorAvatar) ?>" alt="" width="56" height="56" loading="lazy">
                    <?php else: ?><span class="avatar avatar-lg avatar-text"><?= $this->e(mb_strtoupper(mb_substr($article->authorName, 0, 1))) ?></span><?php endif; ?>
                    <div>
                        <p class="author-box-name"><a href="/author/<?= $this->e($article->authorSlug) ?>"><?= $this->e($article->authorName) ?></a></p>
                        <p class="muted small">Следите за новыми материалами автора</p>
                    </div>
                    <?php if ($user && $article->authorId !== $user->id): ?>
                        <button type="button" class="btn btn-ghost btn-sm follow-btn<?= $following ? ' is-active' : '' ?>" data-author-id="<?= (int) $article->authorId ?>" aria-pressed="<?= $following ? 'true' : 'false' ?>"><?= $following ? 'Вы подписаны' : 'Подписаться' ?></button>
                    <?php elseif (!$user): ?>
                        <a class="btn btn-ghost btn-sm" href="/login">Подписаться</a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($related !== []): ?>
        <section class="container related" aria-labelledby="related-title">
            <h2 id="related-title" class="section-title">Читайте также</h2>
            <div class="feed-grid"><?php foreach ($related as $item): ?><?= $this->partial('partials/article-card', ['article' => $item, 'variant' => 'default']) ?><?php endforeach; ?></div>
        </section>
    <?php endif; ?>

    <?php if ($moreLikeThis !== []): ?>
        <section class="container related" aria-labelledby="more-title">
            <h2 id="more-title" class="section-title">Связанные материалы</h2>
            <div class="feed-grid"><?php foreach ($moreLikeThis as $item): ?><?= $this->partial('partials/article-card', ['article' => $item, 'variant' => 'default']) ?><?php endforeach; ?></div>
        </section>
    <?php endif; ?>

    <?php if ($canComment): ?>
        <section class="container container-narrow comments-section" id="comments" aria-labelledby="comments-title">
            <h2 id="comments-title" class="section-title">Комментарии <span class="muted" id="comments-count"><?= (int) $totalComments ?></span></h2>
            <?php if ($user && $user->isEmailVerified()): ?>
                <form class="comment-form" id="comment-form" action="/api/articles/<?= (int) $article->id ?>/comments" method="post">
                    <?= $this->csrfField() ?>
                    <label class="visually-hidden" for="comment-body">Ваш комментарий</label>
                    <textarea id="comment-body" name="body" rows="3" maxlength="3000" placeholder="Напишите комментарий. Будьте вежливы — <?= '' ?>правила обсуждения доступны по ссылке ниже" required></textarea>
                    <div class="form-row">
                        <span class="muted small"><a href="/comment-rules" target="_blank">Правила комментариев</a> · можно изменить в течение 5 минут</span>
                        <button type="submit" class="btn btn-primary btn-sm">Отправить</button>
                    </div>
                </form>
            <?php elseif ($user): ?>
                <p class="notice">Подтвердите email, чтобы оставлять комментарии. <a href="/verify-email/pending">Отправить письмо ещё раз</a></p>
            <?php else: ?>
                <p class="notice"><a href="/login">Войдите</a> или <a href="/register">зарегистрируйтесь</a>, чтобы участвовать в обсуждении.</p>
            <?php endif; ?>

            <ul class="comments" id="comments-list" data-viewer="<?= $user ? (int) $user->id : 0 ?>">
                <?php foreach ($comments as $comment): ?><?= $this->partial('partials/comment', ['comment' => $comment, 'viewer' => $user, 'withChildren' => true]) ?><?php endforeach; ?>
            </ul>
            <p class="muted" id="comments-empty" <?= $comments === [] ? '' : 'hidden' ?>>Комментариев пока нет — будьте первым.</p>
        </section>

        <dialog class="dialog" id="report-dialog">
            <form method="dialog" id="report-form">
                <h3>Пожаловаться на комментарий</h3>
                <label for="report-reason">Причина</label>
                <select id="report-reason" name="reason">
                    <option value="spam">Спам или реклама</option>
                    <option value="abuse">Оскорбления или травля</option>
                    <option value="offtopic">Не по теме</option>
                    <option value="illegal">Нарушает закон</option>
                    <option value="other">Другое</option>
                </select>
                <label for="report-details">Пояснение (необязательно)</label>
                <textarea id="report-details" name="details" rows="3" maxlength="500"></textarea>
                <div class="dialog-actions">
                    <button type="button" class="btn btn-ghost" data-close>Отмена</button>
                    <button type="submit" class="btn btn-primary" value="send">Отправить</button>
                </div>
            </form>
        </dialog>
    <?php endif; ?>
</article>

<dialog class="dialog lightbox" id="lightbox"><button type="button" class="lightbox-close" data-close aria-label="Закрыть">×</button><img alt=""><p class="lightbox-caption"></p></dialog>

<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/article.js') ?>" defer></script>
<?php if ($canComment): ?><script src="<?= $this->asset('js/comments.js') ?>" defer></script><?php endif; ?>
<?php $this->end(); ?>
