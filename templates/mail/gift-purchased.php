<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Подарок оплачен</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Подписка «<?= $this->e((string) $plan) ?>» оплачена, письмо с кодом отправлено на <?= $this->e($recipientEmail) ?>.</p>
<p>Если письмо не дойдёт, вы можете передать код лично: <strong><?= $this->e($code) ?></strong></p>
