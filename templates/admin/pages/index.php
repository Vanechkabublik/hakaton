<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Page\Page> $items */
$this->layout('layouts/admin');
?>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Страница</th><th>Адрес</th><th>Статус</th><th>Обновлена</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
        <tr><td><a href="/admin/pages/<?= (int) $p->id ?>/edit"><?= $this->e($p->title) ?></a></td><td>/<?= $this->e($p->slug) ?></td>
            <td><?= $p->isPublished ? '<span class="badge badge-success">опубликована</span>' : '<span class="badge badge-muted">скрыта</span>' ?></td>
            <td><?= $this->e($this->short($p->updatedAt)) ?></td>
            <td class="actions"><a class="btn btn-ghost btn-xs" href="/<?= $this->e($p->slug) ?>" target="_blank">Открыть</a> <a class="btn btn-ghost btn-xs" href="/admin/pages/<?= (int) $p->id ?>/edit">Править</a></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<p class="muted small">Юридические тексты (соглашение, политика конфиденциальности) подготовлены как шаблоны — перед запуском проверьте их с юристом.</p>
