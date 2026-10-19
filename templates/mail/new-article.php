<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<p style="color:#6b7280;margin:0 0 6px;"><?= $this->e((string) $author) ?> опубликовал(а) новый материал</p>
<?php if (!empty($cover)): ?><a href="<?= $this->e($url) ?>"><img src="<?= $this->e($cover) ?>" alt="" width="544" style="width:100%;max-width:544px;border-radius:10px;border:0;margin:6px 0 12px;"></a><?php endif; ?>
<h1 style="font-size:24px;margin:0 0 10px;line-height:1.25;"><a href="<?= $this->e($url) ?>" style="color:#14171f;text-decoration:none;"><?= $this->e($title) ?></a></h1>
<p style="color:#384152;"><?= $this->e($lead) ?></p>
<?= $this->partial('mail/_button', ['url' => $url, 'label' => 'Читать']) ?>
