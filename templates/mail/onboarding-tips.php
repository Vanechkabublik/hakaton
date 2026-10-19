<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Как получить от сайта максимум</h1>
<p><?= $this->e($name) ?>, несколько полезных возможностей:</p>
<ol style="padding-left:20px;line-height:1.8;"><li><strong>Тёмная тема</strong> — переключатель в шапке сайта;</li><li><strong>Подписка на авторов</strong> — мы сообщим о новых материалах;</li><li><strong>Дайджест</strong> — главное за неделю или сутки на почту;</li><li><strong>Push-уведомления</strong> — включаются в личном кабинете.</li></ol>
<?= $this->partial('mail/_button', ['url' => $settingsUrl, 'label' => 'Настроить уведомления']) ?>
