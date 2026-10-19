<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Восстановление пароля</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Мы получили запрос на смену пароля. Ссылка действует 1 час и сработает только один раз.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Создать новый пароль']) ?>
<p style="color:#6b7280;font-size:14px;">Если это были не вы — ничего не делайте, пароль останется прежним.</p>
