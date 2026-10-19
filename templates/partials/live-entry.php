<?php
/** @var \App\Core\View\View $this */
/** @var array<string,mixed> $entry */
$posted = new \DateTimeImmutable((string) $entry['posted_at']);
?>
<article class="live-entry<?= !empty($entry['is_pinned']) ? ' is-pinned' : '' ?>" id="live-<?= (int) $entry['id'] ?>" data-id="<?= (int) $entry['id'] ?>">
    <header>
        <time datetime="<?= $this->e($this->iso($posted)) ?>"><?= $this->e(\App\Support\Format::local($posted)->format('H:i')) ?></time>
        <?php if (!empty($entry['is_pinned'])): ?><span class="type-badge">Закреплено</span><?php endif; ?>
    </header>
    <?php if (($entry['title'] ?? '') !== ''): ?><h3><?= $this->e($entry['title']) ?></h3><?php endif; ?>
    <div class="prose"><?= $entry['body'] ?></div>
</article>
