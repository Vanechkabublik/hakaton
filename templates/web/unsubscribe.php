<?php
/** @var \App\Core\View\View $this */
/** @var \App\User\User $subject */
/** @var string $token */
$this->layout('layouts/web');
?>
<div class="container container-narrow">
    <h1 class="page-title">Настройки рассылок</h1>
    <p class="muted">Адрес: <strong><?= $this->e($subject->email) ?></strong></p>
    <form method="post" action="/unsubscribe/<?= $this->e($token) ?>" class="card-form">
        <fieldset class="field">
            <legend>Дайджест</legend>
            <?php foreach (['none' => 'Не присылать', 'daily' => 'Каждый день', 'weekly' => 'Раз в неделю'] as $value => $label): ?>
                <label class="radio"><input type="radio" name="digest_frequency" value="<?= $value ?>"<?= $this->checked($subject->digestFrequency === $value) ?>> <?= $label ?></label>
            <?php endforeach; ?>
        </fieldset>
        <label class="check"><input type="checkbox" name="replies" value="1"<?= $this->checked($subject->notifyReplies) ?>> Ответы на мои комментарии</label>
        <label class="check"><input type="checkbox" name="authors" value="1"<?= $this->checked($subject->notifyAuthors) ?>> Новые материалы авторов, на которых я подписан</label>
        <label class="check"><input type="checkbox" name="marketing" value="1"<?= $this->checked($subject->marketingOptIn) ?>> Новости, акции и специальные предложения</label>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сохранить</button>
            <button type="submit" name="unsubscribe_all" value="1" class="btn btn-ghost">Отписаться от всего</button>
        </div>
    </form>
</div>
