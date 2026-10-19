<?php
/** @var \App\Core\View\View $this */
/** @var int $status */
/** @var string $message */
$this->layout('layouts/web');
$titles = [403 => 'Доступ запрещён', 404 => 'Страница не найдена', 419 => 'Сессия истекла', 429 => 'Слишком много запросов', 500 => 'Что-то пошло не так'];
?>
<div class="container container-narrow error-page">
    <p class="error-code"><?= (int) $status ?></p>
    <h1><?= $this->e($titles[$status] ?? 'Ошибка') ?></h1>
    <p class="muted"><?= $this->e($message) ?></p>
    <?php if (!empty($trace)): ?><pre class="debug"><?= $this->e($trace) ?></pre><?php endif; ?>
    <p><a class="btn btn-primary" href="/">На главную</a> <a class="btn btn-ghost" href="/search">Поиск по сайту</a></p>
</div>
