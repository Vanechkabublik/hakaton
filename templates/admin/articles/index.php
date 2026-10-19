<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Article\Article> $items */
/** @var array<string,string> $filters */
/** @var array<string,int> $counts */
/** @var list<\App\Taxonomy\Category> $categories */
/** @var bool $canBulk */
$this->layout('layouts/admin');
$status = $filters['status'] ?? '';
$tabQuery = static fn (string $s): string => '/admin/articles' . ($s !== '' ? '?status=' . $s : '');
$totalAll = array_sum($counts);
?>
<?php $this->start('actions'); ?><a class="btn btn-primary btn-sm" href="/admin/articles/create">+ Новая статья</a><?php $this->end(); ?>

<nav class="tabs" aria-label="Статусы">
    <a href="<?= $tabQuery('') ?>" class="<?= $status === '' ? 'is-active' : '' ?>">Все<span class="count"><?= (int) $totalAll ?></span></a>
    <?php foreach (\App\Article\ArticleStatus::cases() as $s): ?>
        <a href="<?= $tabQuery($s->value) ?>" class="<?= $status === $s->value ? 'is-active' : '' ?>"><?= $this->e($s->label()) ?><span class="count"><?= (int) ($counts[$s->value] ?? 0) ?></span></a>
    <?php endforeach; ?>
</nav>

<div class="toolbar">
    <form method="get" action="/admin/articles">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $this->e($status) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Заголовок или slug" aria-label="Поиск">
        <select name="type" aria-label="Тип"><option value="">Все типы</option><?php foreach (\App\Article\ArticleType::cases() as $t): ?><option value="<?= $t->value ?>"<?= $this->selected($filters['type'] ?? '', $t->value) ?>><?= $this->e($t->label()) ?></option><?php endforeach; ?></select>
        <select name="access" aria-label="Доступ"><option value="">Любой доступ</option><?php foreach (\App\Article\AccessLevel::cases() as $a): ?><option value="<?= $a->value ?>"<?= $this->selected($filters['access'] ?? '', $a->value) ?>><?= $this->e($a->label()) ?></option><?php endforeach; ?></select>
        <select name="category" aria-label="Категория"><option value="">Все категории</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c->id ?>"<?= $this->selected($filters['category'] ?? '', $c->id) ?>><?= $this->e($c->name) ?></option><?php endforeach; ?></select>
        <?php if ($authors !== []): ?><select name="author" aria-label="Автор"><option value="">Все авторы</option><?php foreach ($authors as $a): ?><option value="<?= (int) $a->id ?>"<?= $this->selected($filters['author'] ?? '', $a->id) ?>><?= $this->e($a->name) ?></option><?php endforeach; ?></select><?php endif; ?>
        <button type="submit" class="btn btn-ghost btn-sm">Найти</button>
        <?php if ($filters !== []): ?><a class="btn btn-ghost btn-sm" href="/admin/articles">Сбросить</a><?php endif; ?>
    </form>
    <span class="muted small">Найдено: <?= (int) $total ?></span>
</div>

<form method="post" action="/admin/articles/bulk" class="card-box" id="bulk-form">
    <?= $this->csrfField() ?>
    <?php if ($canBulk): ?>
    <div class="bulk-bar">
        <label class="check" style="margin:0"><input type="checkbox" data-select-all> Выбрать все</label>
        <select name="bulk_action" aria-label="Массовое действие">
            <option value="">Действие с выбранными…</option>
            <option value="publish">Опубликовать</option><option value="archive">В архив</option><option value="draft">В черновики</option>
            <option value="feature">Закрепить на главной</option><option value="unfeature">Открепить</option>
            <optgroup label="Доступ"><?php foreach (\App\Article\AccessLevel::cases() as $a): ?><option value="access:<?= $a->value ?>"><?= $this->e($a->label()) ?></option><?php endforeach; ?></optgroup>
            <optgroup label="Категория"><?php foreach ($categories as $c): ?><option value="category:<?= (int) $c->id ?>"><?= $this->e($c->name) ?></option><?php endforeach; ?></optgroup>
            <option value="delete">Удалить</option>
        </select>
        <button type="submit" class="btn btn-ghost btn-sm" data-bulk-submit>Применить</button>
    </div>
    <?php endif; ?>
    <div class="table-wrap"><table class="table">
        <thead><tr>
            <?php if ($canBulk): ?><th style="width:34px"></th><?php endif; ?>
            <th>Заголовок</th><th>Тип</th><th>Доступ</th><th>Статус</th><th>Автор</th><th>Рубрика</th><th>Дата</th><th class="num">Просм.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $a): ?>
            <tr>
                <?php if ($canBulk): ?><td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $a->id ?>" aria-label="Выбрать"></td><?php endif; ?>
                <td><a class="row-title" href="/admin/articles/<?= (int) $a->id ?>/edit"><?= $this->e($a->title) ?></a><?= $a->isFeatured ? ' <span class="pill">на главной</span>' : '' ?><span class="sub">/news/<?= $this->e($a->slug) ?></span></td>
                <td><?= $this->e($a->type->label()) ?></td>
                <td><?= $a->access === \App\Article\AccessLevel::Free ? '<span class="muted">бесплатно</span>' : '<span class="lock-badge">' . ($a->access === \App\Article\AccessLevel::Premium ? 'Premium' : 'Подписка') . '</span>' ?></td>
                <td><span class="badge badge-<?= $this->e($a->status->tone()) ?>"><?= $this->e($a->status->label()) ?></span><?php if ($a->status === \App\Article\ArticleStatus::Scheduled && $a->publishAt): ?><span class="sub"><?= $this->e($this->short($a->publishAt)) ?></span><?php endif; ?></td>
                <td><?= $this->e((string) $a->authorName) ?></td>
                <td><?= $this->e((string) $a->categoryName) ?></td>
                <td class="nowrap"><?= $this->e($this->short($a->publishedAt ?? $a->updatedAt)) ?></td>
                <td class="num"><?= $this->number($a->viewsCount) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="9" class="muted">Ничего не найдено.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</form>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/articles', 'query' => $filters]) ?>
