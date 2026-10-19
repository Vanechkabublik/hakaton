<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Billing\PromoCode> $items */
/** @var array<int,int> $discounts */
$this->layout('layouts/admin');
?>
<?php $this->start('actions'); ?><a class="btn btn-primary btn-sm" href="/admin/promocodes/create">+ Новый промокод</a><?php $this->end(); ?>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Код</th><th>Скидка</th><th>Использовано</th><th>Действует</th><th>Выдано скидок</th><th>Статус</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $p): ?>
        <tr><td><a href="/admin/promocodes/<?= (int) $p->id ?>/edit"><code><?= $this->e($p->code) ?></code></a><span class="sub"><?= $this->e($p->description) ?></span></td>
            <td><?= $this->e($p->label()) ?></td>
            <td><?= (int) $p->usedCount ?><?= $p->maxUses ? ' / ' . (int) $p->maxUses : '' ?></td>
            <td class="small"><?= $p->validFrom ? $this->e($this->date($p->validFrom)) : '—' ?> → <?= $p->validUntil ? $this->e($this->date($p->validUntil)) : '∞' ?></td>
            <td class="num"><?= $this->e($this->money($discounts[$p->id] ?? 0)) ?></td>
            <td><?= $p->isActive ? '<span class="badge badge-success">активен</span>' : '<span class="badge badge-muted">выключен</span>' ?></td>
            <td class="actions"><a class="btn btn-ghost btn-xs" href="/admin/promocodes/<?= (int) $p->id ?>/edit">Править</a></td></tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="7" class="muted">Промокодов пока нет.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
