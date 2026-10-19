<?php
/** @var \App\Core\View\View $this */
/** @var list<array{id:int,name:string,slug:?string}> $authors */
$this->layout('layouts/account');
?>
<h1>Уведомления</h1>
<form method="post" action="/account/notifications" class="panel">
    <?= $this->csrfField() ?>
    <h2 class="panel-title">Email</h2>
    <fieldset class="field">
        <legend>Дайджест лучших материалов</legend>
        <?php foreach (['none' => 'Не присылать', 'daily' => 'Каждое утро', 'weekly' => 'По понедельникам'] as $value => $label): ?>
            <label class="radio"><input type="radio" name="digest_frequency" value="<?= $value ?>"<?= $this->checked($user->digestFrequency === $value) ?>> <?= $label ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="check"><input type="checkbox" name="notify_replies" value="1"<?= $this->checked($user->notifyReplies) ?>> Ответы на мои комментарии</label>
    <label class="check"><input type="checkbox" name="notify_authors" value="1"<?= $this->checked($user->notifyAuthors) ?>> Новые материалы авторов, на которых я подписан</label>
    <label class="check"><input type="checkbox" name="marketing_opt_in" value="1"<?= $this->checked($user->marketingOptIn) ?>> Новости, акции и специальные предложения</label>
    <button type="submit" class="btn btn-primary">Сохранить</button>
</form>

<section class="panel">
    <h2 class="panel-title">Push-уведомления в браузере</h2>
    <?php if (!$pushAvailable): ?>
        <p class="muted">Push-уведомления пока не включены администратором сайта.</p>
    <?php else: ?>
        <p class="muted">Мгновенные уведомления об ответах на комментарии и новых материалах любимых авторов. Устройств подключено: <strong id="push-devices"><?= (int) $pushDevices ?></strong>.</p>
        <p id="push-status" class="small muted" aria-live="polite"></p>
        <div class="form-actions">
            <button type="button" class="btn btn-primary" id="push-enable" data-key="<?= $this->e($vapid) ?>">Включить на этом устройстве</button>
            <button type="button" class="btn btn-ghost" id="push-disable" hidden>Выключить на этом устройстве</button>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <h2 class="panel-title">Мои авторы</h2>
    <?php if ($authors === []): ?>
        <p class="muted">Вы пока ни на кого не подписаны. Нажмите «Подписаться» на странице автора или статьи.</p>
    <?php else: ?>
        <ul class="plain-list">
            <?php foreach ($authors as $author): ?>
                <li><a href="/author/<?= $this->e((string) $author['slug']) ?>"><?= $this->e($author['name']) ?></a>
                    <button type="button" class="btn btn-ghost btn-sm follow-btn is-active" data-author-id="<?= (int) $author['id'] ?>" aria-pressed="true">Вы подписаны</button></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/push.js') ?>" defer></script>
<script src="<?= $this->asset('js/article.js') ?>" defer></script>
<?php $this->end(); ?>
