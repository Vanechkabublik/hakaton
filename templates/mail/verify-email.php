<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Подтвердите email</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Чтобы завершить регистрацию на <?= $this->e($siteName) ?>, подтвердите адрес почты. Ссылка действует 48 часов.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Подтвердить email']) ?>
<p style="color:#6b7280;font-size:14px;">Если вы не регистрировались, просто проигнорируйте письмо.</p>
