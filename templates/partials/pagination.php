<?php
/** @var \App\Core\View\View $this */
/** @var int $page */
/** @var int $pages */
/** @var string $base */
$query = $query ?? [];
if ($pages <= 1) {
    return;
}
$link = static fn (int $p): string => $base . '?' . http_build_query($query + ['page' => $p]);
?>
<nav class="pagination" aria-label="Страницы">
    <?php if ($page > 1): ?><a class="btn btn-ghost" rel="prev" href="<?= $this->e($link($page - 1)) ?>">← Назад</a><?php endif; ?>
    <span class="muted">Страница <?= (int) $page ?> из <?= (int) $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-ghost" rel="next" href="<?= $this->e($link($page + 1)) ?>">Дальше →</a><?php endif; ?>
</nav>
