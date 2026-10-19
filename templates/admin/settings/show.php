<?php
/** @var \App\Core\View\View $this */
/** @var string $group */
/** @var array<string,string> $groups */
/** @var list<\App\Settings\SettingDefinition> $definitions */
/** @var array<string,string> $values */
/** @var array<string,bool> $secretSet */
$this->layout('layouts/admin');
?>
<div class="settings-layout">
    <nav class="settings-nav" aria-label="Группы настроек">
        <?php foreach ($groups as $key => $label): ?><a href="/admin/settings/<?= $key ?>" class="<?= $group === $key ? 'is-active' : '' ?>"><?= $this->e($label) ?></a><?php endforeach; ?>
    </nav>
    <div>
        <form method="post" action="/admin/settings/<?= $this->e($group) ?>" enctype="multipart/form-data" class="form-panel" novalidate>
            <?= $this->csrfField() ?>
            <h2><?= $this->e($groups[$group]) ?></h2>
            <?php foreach ($definitions as $d): $name = str_replace('.', '__', $d->key); $help = str_replace('{APP_URL}', $appUrl, $d->help); ?>
                <div class="field">
                    <?php if ($d->type === 'bool'): ?>
                        <label class="check"><input type="checkbox" name="<?= $name ?>" value="1"<?= $this->checked(($values[$d->key] ?? '') === '1') ?>> <span><strong><?= $this->e($d->label) ?></strong><?= $help !== '' ? '<br><span class="muted small">' . $this->e($help) . '</span>' : '' ?></span></label>
                    <?php else: ?>
                        <label for="f_<?= $name ?>"><?= $this->e($d->label) ?></label>
                        <?php if ($d->type === 'textarea'): ?>
                            <textarea id="f_<?= $name ?>" name="<?= $name ?>" rows="3"><?= $this->e($values[$d->key] ?? '') ?></textarea>
                        <?php elseif ($d->type === 'select'): ?>
                            <select id="f_<?= $name ?>" name="<?= $name ?>"><?php foreach ($d->options as $v => $l): ?><option value="<?= $this->e($v) ?>"<?= $this->selected($values[$d->key] ?? '', $v) ?>><?= $this->e($l) ?></option><?php endforeach; ?></select>
                        <?php elseif ($d->type === 'secret'): ?>
                            <input id="f_<?= $name ?>" type="password" name="<?= $name ?>" value="" autocomplete="new-password" placeholder="<?= !empty($secretSet[$d->key]) ? '•••••••• (сохранено — оставьте пустым, чтобы не менять)' : 'не задано' ?>">
                            <?php if (!empty($secretSet[$d->key])): ?><span class="secret-set">✓ значение задано</span><?php endif; ?>
                        <?php elseif ($d->type === 'image'): $current = $values[$d->key] ?? ''; ?>
                            <?php if ($current !== ''): ?><div style="margin-bottom:.5rem"><img src="/uploads/<?= $this->e($current) ?>" alt="" style="max-height:60px;background:var(--surface-2);padding:4px;border-radius:6px"> <label class="check" style="display:inline-flex"><input type="checkbox" name="<?= $name ?>__remove" value="1"> удалить</label></div><?php endif; ?>
                            <input id="f_<?= $name ?>" type="file" name="<?= $name ?>" accept="image/png,image/jpeg,image/webp,image/svg+xml">
                        <?php else: ?>
                            <input id="f_<?= $name ?>" type="<?= $d->type === 'int' ? 'number' : 'text' ?>" name="<?= $name ?>" value="<?= $this->e($values[$d->key] ?? '') ?>" <?= $d->type === 'int' ? 'min="0"' : '' ?>>
                        <?php endif; ?>
                        <?php if ($help !== ''): ?><p class="help"><?= $this->e($help) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить</button></div>
        </form>

        <?php if ($group === 'mail'): ?>
            <form method="post" action="/admin/settings/test-mail" class="form-panel"><?= $this->csrfField() ?><h2>Проверка почты</h2><p class="muted">Отправит тестовое письмо на ваш адрес (<?= $this->e($user->email) ?>) и покажет ошибку SMTP, если она есть. Сначала сохраните настройки.</p><button class="btn btn-ghost" type="submit">Отправить тестовое письмо</button></form>
        <?php elseif ($group === 'yookassa'): ?>
            <form method="post" action="/admin/settings/test-yookassa" class="form-panel"><?= $this->csrfField() ?><h2>Проверка подключения</h2>
                <p class="muted">Проверяет shopId и секретный ключ запросом к API ЮKassa. Сохраните настройки перед проверкой.</p>
                <button class="btn btn-ghost" type="submit">Проверить подключение</button>
                <p class="help">URL для HTTP-уведомлений в личном кабинете ЮKassa: <code><?= $this->e($appUrl) ?>/webhooks/yookassa</code> — события payment.succeeded, payment.canceled, refund.succeeded.</p></form>
        <?php elseif ($group === 'push'): ?>
            <form method="post" action="/admin/settings/generate-vapid" class="form-panel" data-confirm="Сгенерировать новые ключи? Прежние push-подписки перестанут работать."><?= $this->csrfField() ?><h2>Ключи VAPID</h2><p class="muted">Нужны для браузерных push-уведомлений. Сгенерируйте один раз.</p><button class="btn btn-ghost" type="submit">Сгенерировать ключи</button></form>
        <?php endif; ?>
    </div>
</div>
