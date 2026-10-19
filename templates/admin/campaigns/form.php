<?php
/** @var \App\Core\View\View $this */
/** @var \App\Newsletter\Campaign $campaign */
/** @var int $recipients */
/** @var list<array<string,mixed>> $failures */
$this->layout('layouts/admin');
$isNew = $campaign->id === 0;
$editable = $isNew || $campaign->isEditable();
$seg = $campaign->segment;
?>
<?php if (!$isNew): ?>
<?php $this->start('actions'); ?><a class="btn btn-ghost btn-sm" href="/admin/campaigns/<?= (int) $campaign->id ?>/preview" target="_blank" rel="noopener">Предпросмотр ↗</a><?php $this->end(); ?>
<?php endif; ?>

<?php if (!$isNew && $campaign->status !== 'draft'): ?>
    <div class="form-panel" style="max-width:none">
        <p><span class="badge"><?= $this->e($campaign->statusLabel()) ?></span>
            <?= $campaign->scheduledAt ? ' запуск: ' . $this->e($this->dateTime($campaign->scheduledAt)) : '' ?></p>
        <?php if ($campaign->totalCount > 0): ?>
            <div class="progress-bar"><span style="width: <?= (int) $campaign->progressPercent() ?>%"></span></div>
            <p class="muted small">Отправлено <?= (int) $campaign->sentCount ?> из <?= (int) $campaign->totalCount ?>, ошибок: <?= (int) $campaign->failedCount ?></p>
        <?php endif; ?>
        <?php if (in_array($campaign->status, ['scheduled', 'sending'], true)): ?>
            <form method="post" action="/admin/campaigns/<?= (int) $campaign->id ?>/cancel" data-confirm="Остановить рассылку?"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" style="color:var(--danger)" type="submit">Остановить</button></form>
        <?php endif; ?>
        <?php if ($failures): ?><details><summary class="small">Ошибки доставки (<?= count($failures) ?>)</summary><table class="table"><tbody><?php foreach ($failures as $f): ?><tr><td><?= $this->e($f['email']) ?></td><td class="muted small"><?= $this->e((string) $f['error']) ?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="/admin/campaigns<?= $isNew ? '' : '/' . (int) $campaign->id ?>" class="form-panel" style="max-width:900px" novalidate>
    <?= $this->csrfField() ?>
    <div class="form-grid">
        <div class="field"><label for="name">Название (для себя)</label><input id="name" type="text" name="name" value="<?= $this->old('name', $campaign->name) ?>" required maxlength="200" <?= $editable ? '' : 'readonly' ?>></div>
        <div class="field"><label for="subject">Тема письма</label><input id="subject" type="text" name="subject" value="<?= $this->old('subject', $campaign->subject) ?>" required maxlength="300" <?= $editable ? '' : 'readonly' ?>></div>
        <div class="field span-all"><label for="preheader">Прехедер (короткий текст рядом с темой)</label><input id="preheader" type="text" name="preheader" value="<?= $this->old('preheader', $campaign->preheader) ?>" maxlength="300" <?= $editable ? '' : 'readonly' ?>></div>
        <div class="field span-all"><label for="body_html">Текст письма (HTML)</label>
            <textarea id="body_html" name="body_html" rows="14" style="font-family:ui-monospace,Menlo,monospace;font-size:.88rem" <?= $editable ? '' : 'readonly' ?>><?= $this->e($this->old('body_html', $campaign->bodyHtml)) ?></textarea>
            <p class="help">Допустимы абзацы, заголовки, списки, ссылки, изображения по https. Подстановка <code>{{name}}</code> — имя получателя. Ссылка отписки добавляется автоматически.</p></div>
        <div class="field"><label for="seg_sub">Подписка</label>
            <select id="seg_sub" name="segment_subscription" <?= $editable ? '' : 'disabled' ?>><?php foreach (['all' => 'Все', 'active' => 'С активной подпиской', 'trial' => 'На пробном периоде', 'none' => 'Никогда не было подписки', 'expired' => 'Бывшие подписчики', 'not_subscribed' => 'Без активной подписки'] as $k => $l): ?><option value="<?= $k ?>"<?= $this->selected($seg['subscription'] ?? 'all', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="seg_act">Активность</label>
            <select id="seg_act" name="segment_activity" <?= $editable ? '' : 'disabled' ?>><?php foreach (['all' => 'Любая', 'active_30' => 'Заходили за 30 дней', 'inactive_30' => 'Не заходили 30+ дней', 'new_7' => 'Зарегистрировались за 7 дней'] as $k => $l): ?><option value="<?= $k ?>"<?= $this->selected($seg['activity'] ?? 'all', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
    </div>
    <p class="muted">Получателей в этом сегменте сейчас: <strong><?= (int) $recipients ?></strong> <span class="small">(только с согласием на рассылки)</span></p>
    <?php if ($editable): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить</button><a class="btn btn-ghost" href="/admin/campaigns">К списку</a></div><?php endif; ?>
</form>

<?php if (!$isNew && $editable): ?>
<div class="two-col">
    <form method="post" action="/admin/campaigns/<?= (int) $campaign->id ?>/test" class="form-panel"><?= $this->csrfField() ?><h2>Тест</h2><p class="muted">Отправить письмо себе перед запуском.</p><button class="btn btn-ghost" type="submit">Отправить тестовое письмо</button></form>
    <form method="post" action="/admin/campaigns/<?= (int) $campaign->id ?>/schedule" class="form-panel" data-confirm="Запустить рассылку? После старта изменить её нельзя."><?= $this->csrfField() ?><h2>Запуск</h2>
        <div class="field"><label for="scheduled_at">Отправить в (пусто — сейчас)</label><input id="scheduled_at" type="datetime-local" name="scheduled_at"></div>
        <button class="btn btn-primary" type="submit">Запустить рассылку</button>
        <p class="help">Письма уходят через очередь небольшими порциями, поэтому большая рассылка не нагружает сервер.</p></form>
</div>
<?php endif; ?>
