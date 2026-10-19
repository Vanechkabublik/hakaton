<?php
/** @var \App\Core\View\View $this */
/** @var bool $available */
/** @var list<array{name:string,size:int,time:int}> $files */
$this->layout('layouts/admin');
?>
<?= $this->partial('admin/security/_tabs', ['tab' => $tab]) ?>
<?php if (!$available): ?><div class="note-box">Утилита <code>pg_dump</code> недоступна в этом окружении, экспорт отключён.</div><?php endif; ?>
<div class="two-col">
    <form method="post" action="/admin/security/database/export" class="form-panel">
        <?= $this->csrfField() ?>
        <h2>Экспорт базы данных</h2>
        <p class="muted">Скачивает полный SQL-дамп (gzip). Файл содержит персональные данные пользователей — храните его в безопасном месте. Для подтверждения введите пароль.</p>
        <div class="field"><label for="export_password">Ваш пароль</label><input id="export_password" type="password" name="password" required autocomplete="current-password"><?= $this->error('password') ?></div>
        <button class="btn btn-primary" type="submit" <?= $available ? '' : 'disabled' ?>>Скачать дамп</button>
    </form>
    <div class="form-panel">
        <h2>Бэкапы на сервере</h2>
        <p class="muted">Ночной бэкап создаётся автоматически в 03:30 (хранится 7 копий) в <code>storage/backups</code>.</p>
        <form method="post" action="/admin/security/database/backup"><?= $this->csrfField() ?><button class="btn btn-ghost btn-sm" type="submit" <?= $available ? '' : 'disabled' ?>>Создать бэкап сейчас</button></form>
        <table class="table" style="margin-top:1rem"><tbody>
            <?php foreach ($files as $f): ?>
                <tr><td><a href="/admin/security/database/backups/<?= $this->e($f['name']) ?>"><?= $this->e($f['name']) ?></a></td><td class="num"><?= $this->e(\App\Support\Format::bytes($f['size'])) ?></td><td class="nowrap"><?= $this->e($this->short((new \DateTimeImmutable('@' . $f['time'])))) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($files === []): ?><tr><td class="muted">Бэкапов пока нет.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
