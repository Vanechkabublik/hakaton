<?php /** @var \App\Core\View\View $this */ /** @var string $url */ /** @var string $label */ ?>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px 0;"><tr><td style="background:#2457d6;border-radius:999px;">
    <a href="<?= $this->e($url) ?>" style="display:inline-block;padding:13px 26px;color:#ffffff;text-decoration:none;font-weight:700;font-size:16px;"><?= $this->e($label) ?></a>
</td></tr></table>
<p style="font-size:13px;color:#6b7280;margin:0 0 8px;">Если кнопка не работает, скопируйте ссылку в браузер:<br><a href="<?= $this->e($url) ?>" style="color:#2457d6;word-break:break-all;"><?= $this->e($url) ?></a></p>
