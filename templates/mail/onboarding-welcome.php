<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Добро пожаловать, <?= $this->e($name) ?>!</h1>
<p>Рады видеть вас на <?= $this->e($siteName) ?>. Вот что можно сделать прямо сейчас:</p>
<ul style="padding-left:20px;line-height:1.8;"><li>Читать новости, аналитику и лонгриды;</li><li>Сохранять статьи в закладки и возвращаться к ним позже;</li><li>Комментировать и подписываться на любимых авторов.</li></ul>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Перейти на сайт']) ?>
<p style="font-size:14px;color:#6b7280;">Частоту писем можно изменить в <a href="<?= $this->e($accountUrl) ?>" style="color:#2457d6;">настройках уведомлений</a>.</p>
