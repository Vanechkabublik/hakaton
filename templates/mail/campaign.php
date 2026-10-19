<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout', ['preheader' => $preheader ?? '']); ?>
<?= $body ?>
