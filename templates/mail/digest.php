<?php /** @var \App\Core\View\View $this */ $this->layout('mail/layout'); ?>
<h1 style="font-size:22px;margin:0 0 6px;"><?= $frequency === 'daily' ? 'Главное за сутки' : 'Главное за неделю' ?></h1>
<p style="color:#6b7280;margin:0 0 18px;">Здравствуйте, <?= $this->e($name) ?>! Лучшие материалы <?= $frequency === 'daily' ? 'последних суток' : 'недели' ?>:</p>
<?php foreach ($articles as $article): ?>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;padding-bottom:18px;border-bottom:1px solid #eceff4;"><tr>
        <?php if ($article['cover']): ?><td width="140" valign="top" style="padding-right:14px;"><a href="<?= $this->e($article['url']) ?>"><img src="<?= $this->e($article['cover']) ?>" alt="" width="140" style="width:140px;border-radius:8px;border:0;"></a></td><?php endif; ?>
        <td valign="top">
            <?php if ($article['category']): ?><div style="font-size:12px;color:#2457d6;font-weight:700;text-transform:uppercase;letter-spacing:.04em;"><?= $this->e($article['category']) ?><?= $article['paid'] ? ' · по подписке' : '' ?></div><?php endif; ?>
            <a href="<?= $this->e($article['url']) ?>" style="font-size:18px;font-weight:700;color:#14171f;text-decoration:none;line-height:1.3;"><?= $this->e($article['title']) ?></a>
            <div style="font-size:14px;color:#6b7280;margin-top:4px;"><?= $this->e($article['lead']) ?></div>
            <div style="font-size:12px;color:#9aa1ae;margin-top:4px;"><?= (int) $article['minutes'] ?> мин чтения</div>
        </td>
    </tr></table>
<?php endforeach; ?>
