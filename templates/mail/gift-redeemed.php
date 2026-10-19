<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Ваш подарок активирован</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! <?= $this->e($recipient) ?> активировал(а) подписку «<?= $this->e((string) $plan) ?>», которую вы подарили. Спасибо, что делитесь хорошими материалами!</p>
