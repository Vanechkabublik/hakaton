<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Media\Media> $items */
/** @var array<int,int> $usage */
$this->layout('layouts/admin');
?>
<form method="post" action="/admin/media" enctype="multipart/form-data" class="dropzone" id="dropzone">
    <?= $this->csrfField() ?>
    <p><strong>Перетащите изображения сюда</strong> или выберите файлы. Они автоматически уменьшаются до нескольких размеров и конвертируются в WebP.</p>
    <input type="file" name="files[]" id="media-files" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
    <button type="submit" class="btn btn-primary btn-sm">Загрузить</button>
    <p class="help">JPEG, PNG, WebP, GIF — до 12 МБ и 40 Мп. Анимация GIF не сохраняется. Всего в медиатеке: <?= (int) $total ?> файл(ов), <?= $this->e(\App\Support\Format::bytes($size)) ?>.</p>
</form>
<div class="toolbar"><form method="get"><input type="search" name="q" value="<?= $this->e($query) ?>" placeholder="Поиск по имени и описанию"><button class="btn btn-ghost btn-sm" type="submit">Найти</button></form></div>
<div class="media-grid">
    <?php foreach ($items as $m): ?>
        <div class="media-item">
            <img src="<?= $this->e($m->url(320)) ?>" alt="<?= $this->e($m->alt) ?>" loading="lazy">
            <div class="meta">
                <span title="<?= $this->e($m->originalName) ?>"><?= $this->e($this->truncate($m->originalName, 24)) ?></span>
                <span><?= (int) $m->width ?>×<?= (int) $m->height ?> · <?= $this->e(\App\Support\Format::bytes($m->sizeBytes)) ?> · <?= $this->e($this->date($m->createdAt)) ?></span>
                <form method="post" action="/admin/media/<?= (int) $m->id ?>"><?= $this->csrfField() ?><input type="text" name="alt" value="<?= $this->e($m->alt) ?>" placeholder="Описание (alt)" maxlength="300" aria-label="Описание изображения"><button class="btn btn-ghost btn-xs" type="submit" style="margin-top:4px">Сохранить</button></form>
                <span class="small"><button type="button" class="link-btn" data-copy="<?= $this->e($this->url($m->url(1280))) ?>">копировать ссылку</button></span>
                <?php if ($canDelete): ?>
                    <?php if (($usage[$m->id] ?? 0) > 0): ?><span class="pill">используется: <?= (int) $usage[$m->id] ?></span>
                    <?php else: ?><form method="post" action="/admin/media/<?= (int) $m->id ?>/delete" data-confirm="Удалить файл?"><?= $this->csrfField() ?><button class="link-btn" type="submit" style="color:var(--danger)">удалить</button></form><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($items === []): ?><p class="muted">Файлов пока нет.</p><?php endif; ?>
</div>
<?= $this->partial('partials/pagination', ['page' => $page, 'pages' => $pages, 'base' => '/admin/media', 'query' => $query !== '' ? ['q' => $query] : []]) ?>
<?php $this->start('scripts'); ?>
<script nonce="<?= $this->e($nonce) ?>">
(function () {
    var zone = document.getElementById('dropzone'), input = document.getElementById('media-files');
    ['dragenter', 'dragover'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (n) { zone.addEventListener(n, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) { input.files = e.dataTransfer.files; zone.submit(); });
})();
</script>
<?php $this->end(); ?>
