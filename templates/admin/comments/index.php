<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Comment\Comment> $items */
/** @var array<string,string> $filters */
/** @var array<string,int> $counts */
/** @var array<int,list<array<string,mixed>>> $reports */
$this->layout('layouts/admin');
$status = $filters['status'] ?? '';
$labels = ['pending' => 'На модерации', 'approved' => 'Опубликованные', 'rejected' => 'Отклонённые', 'spam' => 'Спам'];
?>
<nav class="tabs" aria-label="Статусы">
    <a href="/admin/comments" class="<?= $status === '' && empty($filters['reported']) ? 'is-active' : '' ?>">Все</a>
    <?php foreach ($labels as $key => $label): ?><a href="/admin/comments?status=<?= $key ?>" class="<?= $status === $key ? 'is-active' : '' ?>"><?= $label ?><span class="count"><?= (int) ($counts[$key] ?? 0) ?></span></a><?php endforeach; ?>
    <a href="/admin/comments?reported=1" class="<?= !empty($filters['reported']) ? 'is-active' : '' ?>">С жалобами</a>
</nav>
<div class="toolbar"><form method="get"><?php if ($status): ?><input type="hidden" name="status" value="<?= $this->e($status) ?>"><?php endif; ?><input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Текст, автор или статья"><button class="btn btn-ghost btn-sm" type="submit">Найти</button></form><span class="muted small">Найдено: <?= (int) $total ?></span></div>

<form method="post" action="/admin/comments/bulk" class="card-box" data-bulk>
    <?= $this->csrfField() ?>
    <div class="bulk-bar">
        <label class="check" style="margin:0"><input type="checkbox" data-select-all> Выбрать все</label>
        <select name="bulk_action"><option value="">Действие…</option><option value="approved">Одобрить</option><option value="rejected">Отклонить</option><option value="spam">Спам</option></select>
        <button class="btn btn-ghost btn-sm" type="submit">Применить</button>
    </div>
    <div class="table-wrap"><table class="table">
        <thead><tr><th style="width:34px"></th><th>Комментарий</th><th>Автор</th><th>Статья</th><th>Статус</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($items as $c): ?>
            <tr>
                <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $c->id ?>" aria-label="Выбрать"></td>
                <td style="max-width:460px"><div><?= \App\Support\TextFormatter::commentHtml($this->truncate($c->body, 500)) ?></div>
                    <span class="sub"><?= $this->e($this->short($c->createdAt)) ?> · ♥ <?= (int) $c->likesCount ?><?= $c->reportsCount > 0 ? ' · <strong style="color:var(--danger)">жалоб: ' . (int) $c->reportsCount . '</strong>' : '' ?></span>
                    <?php foreach ($reports[$c->id] ?? [] as $r): ?><span class="sub">⚑ <?= $this->e($r['user_name'] ?? '—') ?>: <?= $this->e($r['reason']) ?><?= $r['details'] !== '' ? ' — ' . $this->e($r['details']) : '' ?></span><?php endforeach; ?></td>
                <td><?= $this->e($c->authorDisplayName()) ?><?php if ($c->userId): ?><a class="sub" href="/admin/users/<?= (int) $c->userId ?>">профиль</a><?php endif; ?></td>
                <td><a href="/news/<?= $this->e((string) $c->articleSlug) ?>#comment-<?= (int) $c->id ?>" target="_blank"><?= $this->e($this->truncate((string) $c->articleTitle, 50)) ?></a></td>
                <td><span class="badge badge-<?= ['approved' => 'success', 'pending' => 'warning', 'rejected' => 'muted', 'spam' => 'danger'][$c->status] ?? 'muted' ?>"><?= $this->e($labels[$c->status] ?? $c->status) ?></span></td>
                <td class="actions">
                    <?php foreach (['approved' => 'Одобрить', 'rejected' => 'Отклонить', 'spam' => 'Спам'] as $s => $label): if ($c->status === $s) { continue; } ?>
                        <button class="btn btn-ghost btn-xs" type="submit" formaction="/admin/comments/<?= (int) $c->id ?>/moderate" name="status" value="<?= $s ?>" formnovalidate><?= $label ?></button>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if ($items === []): ?><tr><td colspan="6" class="muted">Комментариев нет.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</form>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/comments', 'query' => $filters]) ?>
