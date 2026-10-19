<?php /** @var \App\Core\View\View $this */ /** @var string $tab */ ?>
<nav class="tabs" aria-label="Разделы безопасности">
    <?php foreach (['audit' => ['/admin/security', 'Журнал действий'], 'staff' => ['/admin/security/staff', 'Сотрудники и 2FA'], 'database' => ['/admin/security/database', 'База данных'], 'system' => ['/admin/security/system', 'Очередь и планировщик']] as $key => [$href, $label]): ?>
        <a href="<?= $href ?>" class="<?= $tab === $key ? 'is-active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</nav>
