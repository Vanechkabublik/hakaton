<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Подписка скоро закончится</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Ваша подписка «<?= $this->e((string) $plan) ?>» действует до <?= $this->e($this->date($until)) ?>. Автопродление выключено, поэтому после этой даты платные материалы снова закроются.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Продлить подписку']) ?>
