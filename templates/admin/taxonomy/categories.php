<?php
/** @var \App\Core\View\View $this */
/** @var list<\App\Taxonomy\Category> $items */
$this->layout('layouts/admin');
?>
<div class="two-col">
    <div class="card-box"><div class="table-wrap"><table class="table">
        <thead><tr><th>Категория</th><th>Адрес</th><th class="num">Порядок</th><th class="num">Материалов</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $c): ?>
            <tr>
                <td colspan="5">
                    <form method="post" action="/admin/categories/<?= (int) $c->id ?>" class="toolbar" style="margin:0">
                        <?= $this->csrfField() ?>
                        <input type="color" name="color" value="<?= $this->e($c->color) ?>" class="color-input" aria-label="Цвет">
                        <input type="text" name="name" value="<?= $this->e($c->name) ?>" required aria-label="Название" style="min-width:160px">
                        <input type="text" name="slug" value="<?= $this->e($c->slug) ?>" aria-label="Адрес" style="min-width:120px">
                        <input type="number" name="sort_order" value="<?= (int) $c->sortOrder ?>" aria-label="Порядок" style="width:80px;min-width:0">
                        <input type="text" name="description" value="<?= $this->e($c->description) ?>" placeholder="Описание" aria-label="Описание" class="grow">
                        <span class="muted small"><?= (int) $c->articlesCount ?> шт.</span>
                        <button class="btn btn-ghost btn-xs" type="submit">Сохранить</button>
                        <button class="btn btn-ghost btn-xs" type="submit" formaction="/admin/categories/<?= (int) $c->id ?>/delete" data-confirm-click="Удалить категорию? Материалы останутся без рубрики." style="color:var(--danger)">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <form method="post" action="/admin/categories" class="form-panel">
        <h2>Новая категория</h2>
        <?= $this->csrfField() ?>
        <div class="field"><label for="c_name">Название</label><input id="c_name" type="text" name="name" required maxlength="100"></div>
        <div class="field"><label for="c_slug">Адрес (латиницей, необязательно)</label><input id="c_slug" type="text" name="slug" maxlength="100"></div>
        <div class="field"><label for="c_desc">Описание</label><input id="c_desc" type="text" name="description" maxlength="1000"></div>
        <div class="field-row"><div class="field"><label for="c_color">Цвет</label><input id="c_color" type="color" name="color" value="#2563eb" class="color-input"></div><div class="field"><label for="c_sort">Порядок</label><input id="c_sort" type="number" name="sort_order" value="0"></div></div>
        <button class="btn btn-primary" type="submit">Создать</button>
    </form>
</div>
