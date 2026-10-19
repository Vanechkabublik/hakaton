<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Оплата прошла успешно</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Спасибо за оплату.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:12px 0;">
    <tr><td style="padding:8px 0;border-bottom:1px solid #eceff4;color:#6b7280;">Услуга</td><td style="padding:8px 0;border-bottom:1px solid #eceff4;text-align:right;"><?= $this->e($description) ?></td></tr>
    <tr><td style="padding:8px 0;border-bottom:1px solid #eceff4;color:#6b7280;">Сумма</td><td style="padding:8px 0;border-bottom:1px solid #eceff4;text-align:right;font-weight:700;"><?= $this->e($this->money((int) $amount, true)) ?></td></tr>
    <?php if (!empty($card)): ?><tr><td style="padding:8px 0;border-bottom:1px solid #eceff4;color:#6b7280;">Способ оплаты</td><td style="padding:8px 0;border-bottom:1px solid #eceff4;text-align:right;"><?= $this->e($card) ?></td></tr><?php endif; ?>
    <?php if (!empty($until) && empty($isGift)): ?><tr><td style="padding:8px 0;color:#6b7280;">Доступ до</td><td style="padding:8px 0;text-align:right;"><?= $this->e($this->date($until)) ?></td></tr><?php endif; ?>
</table>
<?php if (!empty($autoRenew)): ?><p style="font-size:14px;color:#6b7280;">Включено автопродление: за день до окончания периода мы напомним о списании. Отключить его можно в личном кабинете.</p><?php endif; ?>
<?php if (!empty($isGift)): ?><p>Мы отправили подарок получателю — вам придёт уведомление, когда он активирует подписку.</p><?php endif; ?>
<p style="font-size:14px;color:#6b7280;">Кассовый чек отправит ЮKassa на этот адрес. Подтверждение оплаты можно скачать в разделе «История платежей».</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'История платежей']) ?>
