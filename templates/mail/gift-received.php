<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Вам подарили подписку 🎁</h1>
<p><?= $recipient !== '' ? 'Здравствуйте, ' . $this->e($recipient) . '! ' : '' ?><?= $this->e($from !== '' ? $from : 'Кто-то из ваших друзей') ?> дарит вам подписку «<?= $this->e((string) $plan) ?>» на <?= $this->e($siteName) ?>.</p>
<?php if ($message !== ''): ?><blockquote style="margin:16px 0;padding:8px 16px;border-left:4px solid #2457d6;color:#384152;background:#f6f7f9;"><?= nl2br($this->e($message)) ?></blockquote><?php endif; ?>
<p>Ваш код: <strong style="font-size:20px;letter-spacing:.08em;"><?= $this->e($code) ?></strong></p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Активировать подарок']) ?>
<?php if ($expires): ?><p style="color:#6b7280;font-size:14px;">Код действует до <?= $this->e($this->date($expires)) ?>.</p><?php endif; ?>
