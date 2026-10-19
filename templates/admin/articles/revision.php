<?php
/** @var \App\Core\View\View $this */
/** @var \App\Article\Article $article */
/** @var array<string,mixed> $revision */
/** @var list<array{type:string,text:string}> $diff */
/** @var bool $canRestore */
$this->layout('layouts/admin');
$data = $revision['data'];
?>
<?php $this->start('actions'); ?>
    <a class="btn btn-ghost btn-sm" href="/admin/articles/<?= (int) $article->id ?>/revisions">← Все версии</a>
    <?php if ($canRestore): ?>
        <form method="post" action="/admin/articles/<?= (int) $article->id ?>/revisions/<?= (int) $revision['id'] ?>/restore" data-confirm="Восстановить эту версию? Текущая будет сохранена в истории.">
            <?= $this->csrfField() ?><button type="submit" class="btn btn-primary btn-sm">Восстановить версию</button>
        </form>
    <?php endif; ?>
<?php $this->end(); ?>
<div class="form-panel" style="max-width:none">
    <p class="muted">Версия #<?= (int) $revision['id'] ?> от <?= $this->e($this->dateTime(new \DateTimeImmutable((string) $revision['created_at']))) ?>, автор правки: <?= $this->e((string) ($revision['user_name'] ?? '—')) ?>. <?= $this->e((string) $revision['note']) ?></p>
    <h2><?= $this->e((string) ($data['title'] ?? '')) ?></h2>
    <?php if (!empty($data['subtitle'])): ?><p class="muted"><?= $this->e($data['subtitle']) ?></p><?php endif; ?>
    <?php if (!empty($data['lead'])): ?><p><em><?= $this->e($data['lead']) ?></em></p><?php endif; ?>
    <h2>Сравнение с текущей версией</h2>
    <p class="small muted"><span class="pill" style="background:var(--danger-soft)">удалено</span> — было в этой версии, нет в текущей; <span class="pill" style="background:var(--success-soft)">добавлено</span> — появилось в текущей.</p>
    <div class="diff">
        <?php foreach ($diff as $block): ?>
            <p class="<?= $block['type'] === 'eq' ? '' : $this->e($block['type']) ?>"><?= $this->e($block['text']) ?></p>
        <?php endforeach; ?>
    </div>
</div>
