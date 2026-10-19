<?php
/** @var \App\Core\View\View $this */
/** @var \App\Page\Page $page */
$this->layout('layouts/web');
?>
<div class="container container-narrow page-static">
    <h1><?= $this->e($page->title) ?></h1>
    <div class="prose"><?= $page->body ?></div>
    <p class="muted small">Обновлено: <?= $this->e($this->date($page->updatedAt)) ?></p>
</div>
