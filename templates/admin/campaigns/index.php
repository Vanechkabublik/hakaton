<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Newsletter\Campaign> $items */
$this->layout('layouts/admin');
?>
<?php $this->start('actions'); ?><a class="btn btn-primary btn-sm" href="/admin/campaigns/create">+ Новая рассылка</a><?php $this->end(); ?>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Рассылка</th><th>Статус</th><th>Сегмент</th><th>Прогресс</th><th>Дата</th></tr></thead>
    <tbody>
    <?php foreach ($items as $c): ?>
        <tr><td><a href="/admin/campaigns/<?= (int) $c->id ?>/edit"><?= $this->e($c->name) ?></a><span class="sub"><?= $this->e($c->subject) ?></span></td>
            <td><span class="badge badge-<?= ['draft' => 'neutral', 'scheduled' => 'info', 'sending' => 'warning', 'sent' => 'success', 'canceled' => 'muted'][$c->status] ?? 'neutral' ?>"><?= $this->e($c->statusLabel()) ?></span></td>
            <td class="small"><?= $this->e(['all' => 'все', 'active' => 'с подпиской', 'trial' => 'пробный период', 'none' => 'без подписки', 'expired' => 'бывшие подписчики', 'not_subscribed' => 'без активной подписки'][$c->segment['subscription'] ?? 'all'] ?? 'все') ?>, <?= $this->e(['all' => 'любая активность', 'active_30' => 'активные за 30 дней', 'inactive_30' => 'неактивные 30+ дней', 'new_7' => 'новые за 7 дней'][$c->segment['activity'] ?? 'all'] ?? '') ?></td>
            <td style="min-width:160px"><?php if ($c->totalCount > 0): ?><div class="progress-bar"><span style="width: <?= (int) $c->progressPercent() ?>%"></span></div><span class="small muted">отправлено <?= (int) $c->sentCount ?> из <?= (int) $c->totalCount ?><?= $c->failedCount ? ', ошибок ' . (int) $c->failedCount : '' ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="nowrap"><?= $this->e($this->short($c->scheduledAt ?? $c->createdAt)) ?></td></tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="5" class="muted">Рассылок пока нет.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
<p class="muted small">Письма получают только пользователи, давшие согласие на рассылки (флажок «новости и предложения»), с подтверждённым email. В каждое письмо добавляется ссылка отписки.</p>
