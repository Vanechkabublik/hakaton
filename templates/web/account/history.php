<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Article\Article> $items */
/** @var array<int,array<string,mixed>> $progress */
$this->layout('layouts/account');
?>
<div class="panel-head"><h1>История чтения</h1>
    <?php if ($items !== []): ?><form method="post" action="/account/history/clear" data-confirm="Очистить историю чтения?"><?= $this->csrfField() ?><button type="submit" class="btn btn-ghost btn-sm">Очистить историю</button></form><?php endif; ?>
</div>
<?php if ($items === []): ?>
    <p class="empty-state">История пуста: прочитанные статьи появятся здесь.</p>
<?php else: ?>
    <ul class="history-list">
        <?php foreach ($items as $article): $p = $progress[$article->id] ?? ['progress' => 0, 'completed' => false, 'last_read_at' => null]; ?>
            <li>
                <a class="history-title" href="<?= $this->e($article->url()) ?>"><?= $this->e($article->title) ?></a>
                <span class="muted small"><?= $this->e((string) $article->categoryName) ?> · <?= $p['last_read_at'] ? $this->e($this->ago(new \DateTimeImmutable((string) $p['last_read_at']))) : '' ?></span>
                <span class="progress" role="img" aria-label="Прочитано <?= (int) $p['progress'] ?>%"><span style="width: <?= (int) $p['progress'] ?>%"></span></span>
                <span class="small"><?= $p['completed'] ? '✓ прочитано' : (int) $p['progress'] . '%' ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/account/history']) ?>
<?php endif; ?>
