<?php
/** @var \App\Core\View\View $this */
/** @var \App\Article\Article $article */
$variant = $variant ?? 'default';
$showCover = $article->hasCover() && $variant !== 'compact';
$sizes = $variant === 'featured' ? '(min-width: 900px) 720px, 100vw' : '(min-width: 900px) 360px, (min-width: 600px) 50vw, 100vw';
?>
<article class="card card-<?= $this->e($variant) ?>">
    <?php if ($showCover): ?>
        <a class="card-cover" href="<?= $this->e($article->url()) ?>" tabindex="-1" aria-hidden="true">
            <img src="<?= $this->e((string) $article->coverUrl($variant === 'featured' ? 1280 : 640)) ?>"
                 srcset="<?= $this->e($article->coverSrcset()) ?>" sizes="<?= $this->e($sizes) ?>"
                 alt="" width="640" height="360" loading="<?= $variant === 'featured' ? 'eager' : 'lazy' ?>" decoding="async">
        </a>
    <?php endif; ?>
    <div class="card-body">
        <div class="card-kicker">
            <?php if ($article->categoryName): ?>
                <a class="chip" href="/category/<?= $this->e((string) $article->categorySlug) ?>" style="--chip: <?= $this->e((string) $article->categoryColor) ?>"><?= $this->e($article->categoryName) ?></a>
            <?php endif; ?>
            <?php if ($article->type !== \App\Article\ArticleType::News): ?>
                <span class="type-badge"><?= $this->e($article->type->label()) ?></span>
            <?php endif; ?>
            <?php if ($article->isPaid()): ?>
                <span class="lock-badge" title="Материал по подписке"><svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M17 9V7a5 5 0 0 0-10 0v2H5v12h14V9h-2Zm-8-2a3 3 0 0 1 6 0v2H9V7Z" fill="currentColor"/></svg>Подписка</span>
            <?php endif; ?>
        </div>
        <h3 class="card-title"><a href="<?= $this->e($article->url()) ?>"><?= $this->e($article->title) ?></a></h3>
        <?php if ($variant !== 'compact'): ?>
            <p class="card-lead"><?= $this->e($article->excerpt($variant === 'featured' ? 220 : 150)) ?></p>
        <?php endif; ?>
        <div class="card-meta">
            <?php if ($article->authorName): ?><span><?= $this->e($article->authorName) ?></span><?php endif; ?>
            <time datetime="<?= $this->e($this->iso($article->displayDate())) ?>"><?= $this->e($this->ago($article->displayDate())) ?></time>
            <span><?= (int) $article->readingMinutes ?> мин</span>
            <?php if ($article->commentsCount > 0): ?><span><?= (int) $article->commentsCount ?> комм.</span><?php endif; ?>
        </div>
    </div>
</article>
