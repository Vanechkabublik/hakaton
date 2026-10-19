<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Откройте все материалы</h1>
<p><?= $this->e($name) ?>, с подпиской вы читаете всё без ограничений: аналитику, лонгриды и расследования. Мы независимы и существуем благодаря читателям.</p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Выбрать подписку']) ?>
<p style="font-size:14px;color:#6b7280;">Подписку можно отменить в любой момент в личном кабинете.</p>
