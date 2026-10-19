<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Пробный период начался</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Вам открыт полный доступ по тарифу «<?= $this->e($plan) ?>» на <?= (int) $days ?> <?= $this->plural((int) $days, ['день', 'дня', 'дней']) ?> — до <?= $this->e($this->dateTime($until)) ?>.</p>
<p>Деньги не списываются: после окончания пробного периода доступ закроется, если вы не оформите подписку.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Посмотреть тарифы']) ?>
