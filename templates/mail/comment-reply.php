<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 12px;">Вам ответили</h1>
<p><?= $this->e($author) ?> ответил(а) на ваш комментарий к материалу «<?= $this->e((string) $article) ?>»:</p>
<blockquote style="margin:16px 0;padding:8px 16px;border-left:4px solid #2457d6;color:#384152;background:#f6f7f9;"><?= nl2br($this->e($excerpt)) ?></blockquote>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Перейти к обсуждению']) ?>
