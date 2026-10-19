<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\Plan> $items */
$this->layout('layouts/admin');
?>
<?php $this->start('actions'); ?><a class="btn btn-primary btn-sm" href="/admin/plans/create">+ Новый тариф</a><?php $this->end(); ?>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Тариф</th><th>Код</th><th>Уровень</th><th>Период</th><th class="num">Цена</th><th class="num">Пробный</th><th>Продаётся</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
        <tr<?= !$p->isActive ? ' class="muted-row"' : '' ?>><td><a href="/admin/plans/<?= (int) $p->id ?>/edit"><?= $this->e($p->name) ?></a></td><td><code><?= $this->e($p->code) ?></code></td><td><?= $this->e($p->tier->label()) ?></td><td><?= $this->e($p->period->adjective()) ?></td>
            <td class="num"><?= $this->e($this->money($p->price)) ?></td><td class="num"><?= $p->trialDays > 0 ? (int) $p->trialDays . ' дн.' : '—' ?></td><td><?= $p->isActive ? '<span class="badge badge-success">да</span>' : '<span class="badge badge-muted">нет</span>' ?></td>
            <td class="actions"><a class="btn btn-ghost btn-xs" href="/admin/plans/<?= (int) $p->id ?>/edit">Править</a></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
