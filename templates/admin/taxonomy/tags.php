<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Taxonomy\Tag> $items */
$this->layout('layouts/admin');
?>
<div class="toolbar">
    <form method="get"><input type="search" name="q" value="<?= $this->e($query) ?>" placeholder="Найти тег"><button class="btn btn-ghost btn-sm" type="submit">Найти</button></form>
    <form method="post" action="/admin/tags" class="grow" style="justify-content:flex-end"><?= $this->csrfField() ?><input type="text" name="name" placeholder="Новый тег" required maxlength="60"><button class="btn btn-primary btn-sm" type="submit">Добавить</button></form>
</div>
<div class="card-box"><div class="table-wrap"><table class="table">
    <thead><tr><th>Тег</th><th>Адрес</th><th class="num">Материалов</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $t): ?>
        <tr>
            <td colspan="4">
                <form method="post" action="/admin/tags/<?= (int) $t->id ?>" class="toolbar" style="margin:0">
                    <?= $this->csrfField() ?>
                    <input type="text" name="name" value="<?= $this->e($t->name) ?>" required aria-label="Название">
                    <input type="text" name="slug" value="<?= $this->e($t->slug) ?>" aria-label="Адрес">
                    <span class="muted small"><?= (int) $t->articlesCount ?> шт.</span>
                    <button class="btn btn-ghost btn-xs" type="submit">Сохранить</button>
                    <select name="into" aria-label="Объединить с" style="min-width:140px"><option value="">Объединить с…</option><?php foreach ($items as $o): if ($o->id !== $t->id): ?><option value="<?= (int) $o->id ?>"><?= $this->e($o->name) ?></option><?php endif; endforeach; ?></select>
                    <button class="btn btn-ghost btn-xs" type="submit" formaction="/admin/tags/<?= (int) $t->id ?>/merge" data-confirm-click="Объединить теги? Исходный тег будет удалён.">Объединить</button>
                    <button class="btn btn-ghost btn-xs" type="submit" formaction="/admin/tags/<?= (int) $t->id ?>/delete" data-confirm-click="Удалить тег?" style="color:var(--danger)">Удалить</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?><tr><td colspan="4" class="muted">Тегов пока нет — они создаются при сохранении статей.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
