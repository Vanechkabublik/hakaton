<?php
/** @var \App\Core\View\View $this */
/** @var ?\App\Billing\Subscription $subscription */
/** @var list<\App\Article\Article> $bookmarks */
/** @var list<\App\Article\Article> $recent */
$this->layout('layouts/account');
?>
<h1>Здравствуйте, <?= $this->e($user->firstName()) ?>!</h1>

<?php if (!$user->isEmailVerified()): ?>
    <div class="notice notice-warning">Подтвердите email, чтобы комментировать. <a href="/verify-email/pending">Отправить письмо ещё раз</a></div>
<?php endif; ?>

<div class="stat-grid">
    <section class="panel">
        <h2 class="panel-title">Подписка</h2>
        <?php if ($subscription): ?>
            <p><span class="badge badge-<?= $this->e($subscription->status->tone()) ?>"><?= $this->e($subscription->status->label()) ?></span> <strong><?= $this->e((string) $subscription->planName) ?></strong></p>
            <p class="muted">Доступ до <?= $this->e($this->date($subscription->currentPeriodEnd)) ?><?= $subscription->willRenew() ? ' · автопродление включено' : '' ?></p>
            <a class="btn btn-ghost btn-sm" href="/account/subscription">Управление</a>
        <?php else: ?>
            <p class="muted">У вас нет активной подписки. Оформите её, чтобы читать все материалы без ограничений.</p>
            <a class="btn btn-primary btn-sm" href="/subscribe">Выбрать тариф</a>
        <?php endif; ?>
    </section>
    <section class="panel">
        <h2 class="panel-title">Закладки</h2>
        <p class="stat-number"><?= (int) $bookmarksTotal ?></p>
        <a class="btn btn-ghost btn-sm" href="/account/bookmarks">Открыть</a>
    </section>
    <section class="panel">
        <h2 class="panel-title">Безопасность</h2>
        <p class="muted"><?= $user->hasTwoFactor() ? 'Двухфакторная аутентификация включена' : 'Двухфакторная аутентификация выключена' ?></p>
        <a class="btn btn-ghost btn-sm" href="/account/two-factor">Настроить</a>
    </section>
</div>

<?php if ($bookmarks !== []): ?>
    <h2 class="section-title">Недавние закладки</h2>
    <div class="feed-grid"><?php foreach ($bookmarks as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($recent !== []): ?>
    <h2 class="section-title">Вы читали</h2>
    <div class="feed-grid"><?php foreach ($recent as $article): ?><?= $this->partial('partials/article-card', ['article' => $article, 'variant' => 'default']) ?><?php endforeach; ?></div>
<?php endif; ?>
