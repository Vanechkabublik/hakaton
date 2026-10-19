<?php
/** @var \App\Core\View\View $this */
/** @var \App\Article\Article $article */
/** @var list<array<string,mixed>> $revisions */
$this->layout('layouts/admin');
?>
<?php $this->start('actions'); ?><a class="btn btn-ghost btn-sm" href="/admin/articles/<?= (int) $article->id ?>/edit">← К статье</a><?php $this->end(); ?>
<p class="muted">Материал: <strong><?= $this->e($article->title) ?></strong>. Хранится до 100 последних версий.</p>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>#</th><th>Дата</th><th>Автор правки</th><th>Заголовок на момент правки</th><th>Примечание</th><th class="num">Размер текста</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($revisions as $r): ?>
        <tr>
            <td>#<?= (int) $r['id'] ?></td>
            <td class="nowrap"><?= $this->e($this->short(new \DateTimeImmutable((string) $r['created_at']))) ?></td>
            <td><?= $this->e((string) ($r['user_name'] ?? '—')) ?></td>
            <td><?= $this->e($this->truncate((string) $r['title'], 60)) ?></td>
            <td><?= $this->e((string) $r['note']) ?></td>
            <td class="num"><?= $this->e(\App\Support\Format::bytes((int) $r['body_length'])) ?></td>
            <td class="actions"><a class="btn btn-ghost btn-xs" href="/admin/articles/<?= (int) $article->id ?>/revisions/<?= (int) $r['id'] ?>">Открыть / сравнить</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
