<?php
/** @var \App\Core\View\View $this */
/** @var \App\Page\Page $page */
$this->layout('layouts/admin');
?>
<form method="post" action="/admin/pages/<?= (int) $page->id ?>" class="form-panel" style="max-width:1000px">
    <?= $this->csrfField() ?>
    <div class="field"><label for="title">Заголовок</label><input id="title" type="text" name="title" value="<?= $this->old('title', $page->title) ?>" required maxlength="200"><?= $this->error('title') ?></div>
    <div class="field"><label for="body">Текст (HTML)</label><textarea id="body" name="body" rows="22" style="font-family:ui-monospace,Menlo,monospace;font-size:.88rem"><?= $this->e($page->body) ?></textarea>
        <p class="help">Допустимы абзацы, заголовки h2–h4, списки, ссылки, цитаты, таблицы. Остальное будет удалено при сохранении.</p></div>
    <div class="field"><label for="seo_description">Описание для поисковиков</label><input id="seo_description" type="text" name="seo_description" value="<?= $this->old('seo_description', $page->seoDescription) ?>" maxlength="400"></div>
    <label class="check"><input type="checkbox" name="is_published" value="1"<?= $this->checked($page->isPublished) ?>> Страница опубликована</label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Сохранить</button><a class="btn btn-ghost" href="/<?= $this->e($page->slug) ?>" target="_blank">Открыть на сайте</a></div>
</form>
