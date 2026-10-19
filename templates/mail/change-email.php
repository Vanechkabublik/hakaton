<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Подтвердите новый email</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Вы запросили смену адреса почты в аккаунте. Подтвердите новый адрес по ссылке (действует 24 часа).</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Подтвердить новый email']) ?>
