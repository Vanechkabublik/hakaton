<?php
/** @var \App\Core\View\View $this */
/** @var string $siteName */
/** @var string $siteUrl */
/** @var ?string $logoUrl */
/** @var ?string $unsubscribeUrl */
/** @var string $settingsUrl */
/** @var string $subject */
$preheader = $preheader ?? '';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= $this->e($subject) ?></title>
</head>
<body style="margin:0;padding:0;background:#f3f4f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#14171f;">
<?php if ($preheader !== ''): ?><div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?= $this->e($preheader) ?></div><?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f7;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden;">
    <tr><td style="padding:22px 28px;border-bottom:1px solid #e6e8ee;">
        <a href="<?= $this->e($siteUrl) ?>" style="text-decoration:none;color:#14171f;font-size:20px;font-weight:800;letter-spacing:-0.02em;">
            <?php if ($logoUrl): ?><img src="<?= $this->e($logoUrl) ?>" alt="<?= $this->e($siteName) ?>" height="30" style="vertical-align:middle;border:0;"><?php else: ?><?= $this->e($siteName) ?><?php endif; ?>
        </a>
    </td></tr>
    <tr><td style="padding:28px;font-size:16px;line-height:1.6;color:#14171f;">
        <?= $this->section('content') ?>
    </td></tr>
    <tr><td style="padding:18px 28px 26px;background:#fafbfc;border-top:1px solid #e6e8ee;font-size:12px;line-height:1.5;color:#6b7280;">
        <?= $this->e($siteName) ?> · <a href="<?= $this->e($siteUrl) ?>" style="color:#6b7280;"><?= $this->e((string) parse_url($siteUrl, PHP_URL_HOST)) ?></a><br>
        <?php if ($unsubscribeUrl): ?>
            Вы получили это письмо, потому что подписаны на рассылку. <a href="<?= $this->e($unsubscribeUrl) ?>" style="color:#6b7280;">Отписаться или изменить настройки</a>.
        <?php else: ?>
            Это служебное письмо. Отвечать на него не нужно.
        <?php endif; ?>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
