<?php
/** @var \App\Core\View\View $this */
/** @var \App\Article\Article $article */
/** @var bool $isNew */
/** @var list<\App\Article\ArticleType> $types */
/** @var list<\App\Article\AccessLevel> $accessLevels */
/** @var list<\App\Taxonomy\Category> $categories */
/** @var string $tagNames */
/** @var ?\App\Media\Media $cover */
/** @var list<array{id:int,title:string}> $related */
/** @var list<array{media:\App\Media\Media,caption:string}> $gallery */
/** @var list<array<string,mixed>> $liveEntries */
/** @var list<\App\User\User> $authors */
/** @var bool $canEdit */
/** @var bool $canPublish */
/** @var bool $isEditor */
$this->layout('layouts/admin');
$action = $isNew ? '/admin/articles' : '/admin/articles/' . $article->id;
$status = $article->status;
$isLive = $article->type === \App\Article\ArticleType::Liveblog;
?>
<?php $this->start('actions'); ?>
    <?php if (!$isNew): ?>
        <a class="btn btn-ghost btn-sm" href="<?= $this->e($previewUrl) ?>" target="_blank" rel="noopener">Предпросмотр ↗</a>
        <?php if ($status === \App\Article\ArticleStatus::Published): ?><a class="btn btn-ghost btn-sm" href="/news/<?= $this->e($article->slug) ?>" target="_blank" rel="noopener">На сайте ↗</a><?php endif; ?>
    <?php endif; ?>
<?php $this->end(); ?>

<?php if (!$canEdit): ?><div class="note-box">Вы можете просматривать этот материал, но не редактировать его.</div><?php endif; ?>
<?php if (!$isNew && $article->moderationNote !== '' && $status === \App\Article\ArticleStatus::Draft): ?>
    <div class="note-box"><strong>Комментарий редактора:</strong> <?= nl2br($this->e($article->moderationNote)) ?></div>
<?php endif; ?>

<form method="post" action="<?= $this->e($action) ?>" id="article-form" class="editor-layout" novalidate>
    <?= $this->csrfField() ?>
    <div class="editor-main">
        <div class="field">
            <label for="title" class="visually-hidden">Заголовок</label>
            <input id="title" type="text" name="title" value="<?= $this->old('title', $article->title) ?>" placeholder="Заголовок" required maxlength="300" <?= $canEdit ? '' : 'readonly' ?> class="<?= $this->hasError('title') ? 'has-error' : '' ?>">
            <?= $this->error('title') ?>
        </div>
        <div class="field">
            <label for="subtitle">Подзаголовок</label>
            <input id="subtitle" type="text" name="subtitle" value="<?= $this->old('subtitle', $article->subtitle) ?>" maxlength="500" <?= $canEdit ? '' : 'readonly' ?>>
        </div>
        <div class="field">
            <label for="lead">Анонс (лид) — показывается в ленте и поиске</label>
            <textarea id="lead" name="lead" rows="3" <?= $canEdit ? '' : 'readonly' ?>><?= $this->old('lead', $article->lead) ?></textarea>
        </div>

        <div class="field">
            <label>Текст</label>
            <div class="rte" data-rte data-editable="<?= $canEdit ? '1' : '0' ?>">
                <div class="rte-toolbar" role="toolbar" aria-label="Форматирование текста">
                    <button type="button" data-cmd="bold" title="Жирный (Ctrl+B)"><b>Ж</b></button>
                    <button type="button" data-cmd="italic" title="Курсив (Ctrl+I)"><i>К</i></button>
                    <button type="button" data-cmd="underline" title="Подчёркнутый"><u>Ч</u></button>
                    <span class="sep"></span>
                    <button type="button" data-block="h2" title="Заголовок 2">H2</button>
                    <button type="button" data-block="h3" title="Заголовок 3">H3</button>
                    <button type="button" data-block="p" title="Обычный абзац">¶</button>
                    <button type="button" data-block="blockquote" title="Цитата">❝</button>
                    <span class="sep"></span>
                    <button type="button" data-cmd="insertUnorderedList" title="Маркированный список">• список</button>
                    <button type="button" data-cmd="insertOrderedList" title="Нумерованный список">1. список</button>
                    <span class="sep"></span>
                    <button type="button" data-link title="Ссылка">Ссылка</button>
                    <button type="button" data-image title="Вставить изображение из медиатеки">Фото</button>
                    <button type="button" data-video title="Вставить видео (YouTube, VK, Rutube, Vimeo)">Видео</button>
                    <button type="button" data-cmd="insertHorizontalRule" title="Разделитель">—</button>
                    <button type="button" data-cmd="removeFormat" title="Убрать форматирование">Tx</button>
                    <span class="sep"></span>
                    <button type="button" data-source title="Исходный HTML">&lt;/&gt;</button>
                </div>
                <div class="rte-area" contenteditable="<?= $canEdit ? 'true' : 'false' ?>" data-placeholder="Начните писать…" id="rte-area"><?= $article->body ?></div>
                <textarea class="rte-source" id="rte-source" hidden spellcheck="false"></textarea>
                <textarea name="body" id="body-field" hidden></textarea>
            </div>
            <p class="help">Вставка из Word и сайтов очищается автоматически. Опасный HTML удаляется на сервере.</p>
        </div>

        <div class="form-panel" id="gallery-panel"<?= $article->type === \App\Article\ArticleType::Photo ? '' : ' hidden' ?>>
            <h2>Фотографии репортажа</h2>
            <div class="gallery-edit" id="gallery-list">
                <?php foreach ($gallery as $i => $item): $m = $item['media']; ?>
                    <div class="gallery-row" data-row>
                        <img src="<?= $this->e($m->url(320)) ?>" alt="">
                        <div><input type="hidden" name="gallery[<?= $i ?>][media_id]" value="<?= (int) $m->id ?>"><input type="text" name="gallery[<?= $i ?>][caption]" value="<?= $this->e($item['caption']) ?>" placeholder="Подпись к фото" maxlength="500"></div>
                        <div class="row-actions"><button type="button" class="btn btn-ghost btn-xs" data-up>↑</button><button type="button" class="btn btn-ghost btn-xs" data-down>↓</button><button type="button" class="btn btn-ghost btn-xs" data-remove>✕</button></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="gallery_present" value="1">
            <button type="button" class="btn btn-ghost btn-sm" id="gallery-add">+ Добавить фото</button>
        </div>

        <details class="form-panel"<?= ($article->seoTitle !== '' || $article->seoDescription !== '') ? ' open' : '' ?>>
            <summary><strong>SEO и соцсети</strong></summary>
            <div class="field" style="margin-top:1rem"><label for="seo_title">SEO-заголовок (если отличается)</label><input id="seo_title" type="text" name="seo_title" value="<?= $this->old('seo_title', $article->seoTitle) ?>" maxlength="200"></div>
            <div class="field"><label for="seo_description">Meta description</label><textarea id="seo_description" name="seo_description" rows="2" maxlength="400"><?= $this->old('seo_description', $article->seoDescription) ?></textarea>
                <p class="help">Если пусто, используется анонс по шаблону из настроек SEO.</p></div>
        </details>

        <?php if (!$isNew && $isLive): ?>
            <section class="form-panel" id="live" style="max-width:none">
                <h2>Live-хроника</h2>
                <?php foreach ($liveEntries as $entry): ?>
                    <form method="post" action="/admin/articles/<?= (int) $article->id ?>/live/<?= (int) $entry['id'] ?>" class="live-admin-entry">
                        <?= $this->csrfField() ?>
                        <input type="text" name="title" value="<?= $this->e($entry['title']) ?>" placeholder="Заголовок записи" style="margin-bottom:6px">
                        <textarea name="body" rows="3"><?= $this->e($entry['body']) ?></textarea>
                        <div class="form-actions">
                            <label class="check" style="margin:0"><input type="checkbox" name="pinned" value="1"<?= $this->checked($entry['is_pinned']) ?>> Закрепить</label>
                            <span class="muted small"><?= $this->e($this->short(new \DateTimeImmutable((string) $entry['posted_at']))) ?></span>
                            <button type="submit" class="btn btn-ghost btn-xs">Сохранить</button>
                            <button type="submit" name="do" value="delete" class="btn btn-ghost btn-xs" data-confirm-click="Удалить запись?">Удалить</button>
                        </div>
                    </form>
                <?php endforeach; ?>
                <p class="help">Новую запись добавьте в форме ниже страницы после сохранения статьи.</p>
            </section>
        <?php endif; ?>
    </div>

    <aside class="editor-side">
        <?php if ($canEdit): ?>
        <section class="side-box">
            <h2>Публикация</h2>
            <?php if (!$isNew): ?>
                <p><span class="badge badge-<?= $this->e($status->tone()) ?>"><?= $this->e($status->label()) ?></span></p>
                <p class="small muted">Создано: <?= $this->e($this->short($article->createdAt)) ?><br>Изменено: <?= $this->e($this->short($article->updatedAt)) ?>
                    <?php if ($article->publishedAt): ?><br>Опубликовано: <?= $this->e($this->short($article->publishedAt)) ?><?php endif; ?>
                    <?php if ($status === \App\Article\ArticleStatus::Scheduled && $article->publishAt): ?><br><strong>Выйдет: <?= $this->e($this->short($article->publishAt)) ?></strong><?php endif; ?></p>
            <?php endif; ?>

            <?php if ($isNew): ?>
                <button type="submit" class="btn btn-primary btn-block">Создать черновик</button>
            <?php else: ?>
                <button type="submit" name="action" value="save" class="btn btn-primary btn-block">Сохранить</button>

                <?php if (!$canPublish): ?>
                    <?php if ($status === \App\Article\ArticleStatus::Draft): ?><button type="submit" name="action" value="submit" class="btn btn-ghost btn-block">Отправить на модерацию</button><?php endif; ?>
                    <?php if ($status === \App\Article\ArticleStatus::Pending): ?><button type="submit" name="action" value="draft" class="btn btn-ghost btn-block">Отозвать в черновики</button><?php endif; ?>
                <?php else: ?>
                    <?php if ($status !== \App\Article\ArticleStatus::Published): ?>
                        <button type="submit" name="action" value="publish" class="btn btn-ghost btn-block" style="border-color:var(--success);color:var(--success)">Опубликовать сейчас</button>
                        <div class="field" style="margin-top:.75rem"><label for="publish_at">Запланировать на</label>
                            <input id="publish_at" type="datetime-local" name="publish_at" value="<?= $article->publishAt ? $this->e(\App\Support\Format::local($article->publishAt)->format('Y-m-d\TH:i')) : '' ?>">
                            <button type="submit" name="action" value="schedule" class="btn btn-ghost btn-block" style="margin-top:6px">Запланировать</button></div>
                    <?php endif; ?>
                    <?php if ($status === \App\Article\ArticleStatus::Pending): ?>
                        <div class="field"><label for="moderation_note">Комментарий автору</label><textarea id="moderation_note" name="moderation_note" rows="2" placeholder="Что нужно исправить"></textarea>
                            <button type="submit" name="action" value="reject" class="btn btn-ghost btn-block" style="margin-top:6px">Вернуть на доработку</button></div>
                    <?php endif; ?>
                    <?php if ($status === \App\Article\ArticleStatus::Published): ?>
                        <button type="submit" name="action" value="archive" class="btn btn-ghost btn-block">В архив</button>
                        <button type="submit" name="action" value="draft" class="btn btn-ghost btn-block">Снять с публикации</button>
                    <?php endif; ?>
                    <?php if ($status === \App\Article\ArticleStatus::Archived): ?><button type="submit" name="action" value="draft" class="btn btn-ghost btn-block">Вернуть в черновики</button><?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <section class="side-box">
            <h2>Параметры</h2>
            <div class="field"><label for="type">Тип материала</label>
                <select id="type" name="type" <?= $canEdit ? '' : 'disabled' ?>><?php foreach ($types as $t): ?><option value="<?= $t->value ?>"<?= $this->selected($article->type->value, $t->value) ?>><?= $this->e($t->label()) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="access">Доступ</label>
                <select id="access" name="access" <?= $canEdit ? '' : 'disabled' ?>><?php foreach ($accessLevels as $a): ?><option value="<?= $a->value ?>"<?= $this->selected($article->access->value, $a->value) ?>><?= $this->e($a->label()) ?></option><?php endforeach; ?></select>
                <p class="help">«По подписке» — часть бесплатного лимита в месяц; «Только Premium» — закрыто для остальных.</p></div>
            <div class="field"><label for="category_id">Рубрика</label>
                <select id="category_id" name="category_id" <?= $canEdit ? '' : 'disabled' ?>><option value="">Без рубрики</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c->id ?>"<?= $this->selected($article->categoryId, $c->id) ?>><?= $this->e($c->name) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="tags">Теги (через запятую)</label>
                <input id="tags" type="text" name="tags" value="<?= $this->old('tags', $tagNames) ?>" list="tag-options" autocomplete="off" <?= $canEdit ? '' : 'readonly' ?>><datalist id="tag-options"></datalist></div>
            <label class="check"><input type="checkbox" name="allow_comments" value="1"<?= $this->checked($article->allowComments) ?>> Разрешить комментарии</label>
            <?php if ($isEditor): ?>
                <label class="check"><input type="checkbox" name="is_featured" value="1"<?= $this->checked($article->isFeatured) ?>> Закрепить на главной</label>
                <label class="check" id="is-live-box"<?= $isLive ? '' : ' hidden' ?>><input type="checkbox" name="is_live" value="1"<?= $this->checked($article->isLive) ?>> Трансляция идёт (значок «В эфире»)</label>
                <?php if (!$isNew): ?><div class="field"><label for="slug">Адрес (slug)</label><input id="slug" type="text" name="slug" value="<?= $this->e($article->slug) ?>" maxlength="200"><p class="help">Не меняйте адрес у опубликованных статей — старые ссылки перестанут работать.</p></div><?php endif; ?>
                <?php if ($authors !== []): ?><div class="field"><label for="author_id">Автор</label><select id="author_id" name="author_id"><?php foreach ($authors as $a): ?><option value="<?= (int) $a->id ?>"<?= $this->selected($article->authorId ?? 0, $a->id) ?>><?= $this->e($a->name) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="side-box">
            <h2>Обложка</h2>
            <div class="cover-picker">
                <img class="cover-preview" id="cover-preview" src="<?= $cover ? $this->e($cover->url(320)) : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ?>" alt="">
                <input type="hidden" name="cover_media_id" id="cover-id" value="<?= $cover ? (int) $cover->id : '' ?>">
                <?php if ($canEdit): ?><div><button type="button" class="btn btn-ghost btn-sm" id="cover-choose">Выбрать</button> <button type="button" class="btn btn-ghost btn-sm" id="cover-clear"<?= $cover ? '' : ' hidden' ?>>Убрать</button></div><?php endif; ?>
            </div>
            <div class="field" style="margin-top:.75rem"><label for="cover_caption">Подпись к обложке</label><input id="cover_caption" type="text" name="cover_caption" value="<?= $this->old('cover_caption', $article->coverCaption) ?>" maxlength="300"></div>
        </section>

        <section class="side-box">
            <h2>Читайте также</h2>
            <p class="help" style="margin-top:0">Материалы, которые редакция рекомендует вручную (до 8). Остальные «связанные» подбираются автоматически по тегам.</p>
            <div class="chip-list" id="related-list">
                <?php foreach ($related as $r): ?>
                    <span class="chip-item"><span><?= $this->e($this->truncate($r['title'], 38)) ?></span><input type="hidden" name="related_ids[]" value="<?= (int) $r['id'] ?>"><button type="button" aria-label="Убрать" data-remove-chip>×</button></span>
                <?php endforeach; ?>
            </div>
            <?php if ($canEdit): ?>
            <div class="suggest-list">
                <input type="search" id="related-search" placeholder="Найти статью…" autocomplete="off" data-except="<?= (int) $article->id ?>">
                <ul id="related-results" hidden></ul>
            </div>
            <?php endif; ?>
        </section>

        <?php if (!$isNew): ?>
        <section class="side-box">
            <h2>Служебное</h2>
            <p class="small"><a href="/admin/articles/<?= (int) $article->id ?>/revisions">История правок</a></p>
            <p class="small">
                <button type="button" class="link-btn" data-copy="<?= $this->e($this->url($signedPreview)) ?>">Скопировать ссылку для предпросмотра без входа (7 дней)</button>
            </p>
            <?php if ($gate->allows($user, 'articles.delete') || ($article->authorId === $user->id && $status === \App\Article\ArticleStatus::Draft)): ?>
                <button type="submit" form="delete-form" class="btn btn-ghost btn-sm" style="color:var(--danger)" data-confirm-click="Удалить материал без возможности восстановления?">Удалить материал</button>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </aside>
</form>

<?php if (!$isNew): ?>
    <form method="post" action="/admin/articles/<?= (int) $article->id ?>/delete" id="delete-form"><?= $this->csrfField() ?></form>
    <?php if ($isLive): ?>
        <section class="form-panel" style="max-width:none">
            <h2>Добавить запись в хронику</h2>
            <form method="post" action="/admin/articles/<?= (int) $article->id ?>/live">
                <?= $this->csrfField() ?>
                <div class="field"><label for="live_title">Заголовок (необязательно)</label><input id="live_title" type="text" name="title" maxlength="300"></div>
                <div class="field"><label for="live_body">Текст</label><textarea id="live_body" name="body" rows="4" required></textarea></div>
                <label class="check"><input type="checkbox" name="pinned" value="1"> Закрепить</label>
                <button type="submit" class="btn btn-primary btn-sm">Опубликовать запись</button>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>

<dialog class="dialog" id="media-dialog">
    <div class="panel-head"><h3>Медиатека</h3><button type="button" class="btn btn-ghost btn-xs" data-close>Закрыть</button></div>
    <div class="toolbar"><input type="search" id="media-search" placeholder="Поиск по названию" class="grow"><label class="btn btn-primary btn-sm" for="media-upload">Загрузить</label><input type="file" id="media-upload" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden></div>
    <div class="media-grid" id="media-results"></div>
    <div class="center"><button type="button" class="btn btn-ghost btn-sm" id="media-more" hidden>Показать ещё</button></div>
</dialog>

<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/editor.js') ?>" defer></script>
<?php $this->end(); ?>
