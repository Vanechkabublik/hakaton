<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Вас пригласили в редакцию</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! <?= $this->e($inviter) ?> создал(а) для вас аккаунт на <?= $this->e($siteName) ?> с ролью «<?= $this->e($role) ?>».</p>
<p>Задайте пароль по ссылке (действует 3 дня). Для редакторов и администраторов при первом входе потребуется настроить двухфакторную аутентификацию.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Задать пароль']) ?>
