<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Пробный период скоро закончится</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Через <?= (int) $days ?> <?= $this->plural((int) $days, ['день', 'дня', 'дней']) ?> (<?= $this->e($this->date($until)) ?>) закончится пробный доступ. Оформите подписку, чтобы продолжить читать без ограничений.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Выбрать тариф']) ?>
