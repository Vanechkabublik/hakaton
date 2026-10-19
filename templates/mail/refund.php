<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Возврат средств</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! Мы оформили возврат <strong><?= $this->e($this->money((int) $amount, true)) ?></strong> по платежу «<?= $this->e($description) ?>».</p>
<p>Деньги вернутся на тот способ оплаты, которым вы платили, — обычно в течение 1–5 рабочих дней в зависимости от банка.</p>
<?php if (!empty($full)): ?><p>Возврат полный, поэтому доступ по этой оплате закрыт.</p><?php endif; ?>
