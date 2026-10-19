<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Завтра мы продлим подписку</h1>
<p>Здравствуйте, <?= $this->e($name) ?>! <?= $this->e($this->date($until)) ?> с вашей карты<?= !empty($card) ? ' (' . $this->e($card) . ')' : '' ?> будет списано <strong><?= $this->e($this->money((int) $amount, true)) ?></strong> за подписку «<?= $this->e((string) $plan) ?>».</p>
<p>Если вы больше не хотите продлевать, отключите автопродление — доступ сохранится до конца оплаченного периода.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Управление подпиской']) ?>
