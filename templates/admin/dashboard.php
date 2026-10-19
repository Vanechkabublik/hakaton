<?php
/** @var \App\Core\View\View $this */
/** @var array<string,mixed> $stats */
/** @var bool $finance */
$this->layout('layouts/admin');
$days = $stats['days'];
$delta = static function (int|float $now, int|float $prev): array {
    if ($prev <= 0) { return [$now > 0 ? 'новое' : '—', '']; }
    $pct = ($now - $prev) / $prev * 100;
    return [($pct >= 0 ? '+' : '−') . number_format(abs($pct), 0, ',', '') . '% к прошлому периоду', $pct >= 0 ? 'is-up' : 'is-down'];
};
$f = $stats['finance'] ?? null;
$u = $stats['users'];
$t = $stats['traffic'];
$pw = $stats['paywall'] ?? null;
$chartData = [
    'revenue' => $stats['series']['revenue'] ?? [],
    'subscriptions' => $stats['series']['subscriptions'] ?? [],
    'users' => $stats['series']['users'],
    'views' => $stats['series']['views'],
    'completed' => $stats['series']['completed'],
];
?>
<div class="toolbar">
    <nav class="range-switch" aria-label="Период">
        <?php foreach ([7 => '7 дней', 30 => '30 дней', 90 => '90 дней', 365 => 'Год'] as $d => $label): ?>
            <a href="/admin?days=<?= $d ?>" class="<?= $days === $d ? 'is-active' : '' ?>"<?= $days === $d ? ' aria-current="true"' : '' ?>><?= $label ?></a>
        <?php endforeach; ?>
    </nav>
    <span class="muted small">с <?= $this->e($this->date($stats['from'])) ?> по сегодня; сравнение — с предыдущими <?= (int) $days ?> днями</span>
</div>

<?php if ($finance && $f): ?>
<section aria-label="Финансы">
    <div class="kpi-grid">
        <div class="kpi kpi-hero">
            <div class="kpi-label">Выручка за период (за вычетом возвратов)</div>
            <div class="kpi-value"><?= $this->e($this->money($f['revenue'])) ?></div>
            <?php [$text, $cls] = $delta($f['revenue'], $f['revenue_prev']); ?>
            <div class="kpi-delta <?= $cls ?>"><?= $this->e($text) ?></div>
        </div>
        <div class="kpi"><div class="kpi-label">MRR</div><div class="kpi-value"><?= $this->e($this->money($f['mrr'])) ?></div><div class="kpi-sub">ARR ≈ <?= $this->e($this->money($f['arr'])) ?></div></div>
        <div class="kpi"><div class="kpi-label">Активных подписок</div><div class="kpi-value"><?= $this->number($f['active']) ?></div><div class="kpi-sub">из них пробных: <?= (int) $f['trial'] ?></div></div>
        <div class="kpi"><div class="kpi-label">Новых подписок</div><div class="kpi-value"><?= $this->number($f['new_subs']) ?></div>
            <?php [$text, $cls] = $delta($f['new_subs'], $f['new_subs_prev']); ?><div class="kpi-delta <?= $cls ?>"><?= $this->e($text) ?></div></div>
        <div class="kpi"><div class="kpi-label">Конверсия пейволла</div><div class="kpi-value"><?= $this->e(\App\Support\Format::percent((float) $pw['conversion'], 2)) ?></div><div class="kpi-sub">покупки / показы пейволла</div></div>
        <div class="kpi"><div class="kpi-label">Отток за период</div><div class="kpi-value"><?= $this->number($f['churned']) ?></div><div class="kpi-sub">возвраты: <?= $this->e($this->money($f['refunds'])) ?></div></div>
    </div>
</section>
<?php endif; ?>

<section aria-label="Аудитория">
    <div class="kpi-grid">
        <div class="kpi"><div class="kpi-label">Новых пользователей</div><div class="kpi-value"><?= $this->number($u['new']) ?></div>
            <?php [$text, $cls] = $delta($u['new'], $u['new_prev']); ?><div class="kpi-delta <?= $cls ?>"><?= $this->e($text) ?></div></div>
        <div class="kpi"><div class="kpi-label">Всего пользователей</div><div class="kpi-value"><?= $this->number($u['total']) ?></div><div class="kpi-sub">подтвердили email: <?= $this->number($u['verified']) ?></div></div>
        <div class="kpi"><div class="kpi-label">Просмотров статей</div><div class="kpi-value"><?= $this->number($t['views']) ?></div>
            <?php [$text, $cls] = $delta($t['views'], $t['views_prev']); ?><div class="kpi-delta <?= $cls ?>"><?= $this->e($text) ?></div></div>
        <div class="kpi"><div class="kpi-label">Уникальных читателей</div><div class="kpi-value"><?= $this->number($t['visitors']) ?></div></div>
        <div class="kpi"><div class="kpi-label">Дочитываемость</div><div class="kpi-value"><?= $this->e(\App\Support\Format::percent((float) $t['completion_rate'])) ?></div><div class="kpi-sub">среднее время: <?= (int) floor($t['avg_seconds'] / 60) ?> мин <?= (int) ($t['avg_seconds'] % 60) ?> с</div></div>
    </div>
</section>

<section class="chart-grid" aria-label="Динамика">
    <?php if ($finance): ?>
        <figure class="chart-card"><div class="chart-head"><h2 class="chart-title">Выручка по дням, ₽</h2><span class="chart-total" data-total="revenue"></span></div><div class="chart" data-chart="revenue" data-type="bar" data-format="money" data-label="Выручка"></div></figure>
        <figure class="chart-card"><div class="chart-head"><h2 class="chart-title">Новые подписки по дням</h2><span class="chart-total" data-total="subscriptions"></span></div><div class="chart" data-chart="subscriptions" data-type="bar" data-format="count" data-label="Подписки"></div></figure>
    <?php endif; ?>
    <figure class="chart-card"><div class="chart-head"><h2 class="chart-title">Новые пользователи по дням</h2><span class="chart-total" data-total="users"></span></div><div class="chart" data-chart="users" data-type="line" data-format="count" data-label="Регистрации"></div></figure>
    <figure class="chart-card"><div class="chart-head"><h2 class="chart-title">Просмотры статей по дням</h2><span class="chart-total" data-total="views"></span></div><div class="chart" data-chart="views" data-type="line" data-format="count" data-label="Просмотры"></div></figure>
    <figure class="chart-card"><div class="chart-head"><h2 class="chart-title">Дочитывания по дням</h2><span class="chart-total" data-total="completed"></span></div><div class="chart" data-chart="completed" data-type="line" data-format="count" data-label="Дочитывания"></div></figure>
</section>

<section class="two-col">
    <div class="chart-card">
        <div class="chart-head"><h2 class="chart-title">Источники трафика</h2><span class="chart-total">просмотры за период</span></div>
        <?php $maxSource = max(1, ...array_map(static fn ($s) => $s['count'], $stats['sources'] ?: [['count' => 1]])); ?>
        <?php if ($stats['sources'] === []): ?><p class="muted">Данных пока нет.</p><?php endif; ?>
        <div class="hbars" role="list">
            <?php foreach ($stats['sources'] as $s): ?>
                <div class="hbar" role="listitem" title="<?= $this->e($s['label']) ?>: <?= (int) $s['count'] ?>">
                    <span><?= $this->e($s['label']) ?></span>
                    <div class="hbar-track"><div class="hbar-fill" style="width: <?= max(1, round($s['count'] / $maxSource * 100)) ?>%"></div></div>
                    <span class="hbar-value"><?= $this->number($s['count']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($stats['referrers'] !== []): ?>
            <h3 class="small muted" style="margin-top:1.25rem">Внешние сайты</h3>
            <table class="table"><tbody><?php foreach ($stats['referrers'] as $r): ?><tr><td><?= $this->e($r['host']) ?></td><td class="num"><?= $this->number($r['count']) ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div>

    <?php if ($finance && $pw): ?>
    <div class="chart-card">
        <div class="chart-head"><h2 class="chart-title">Воронка пейволла</h2><span class="chart-total">уникальные посетители</span></div>
        <?php $base = max(1, $pw['shown']); ?>
        <div class="funnel">
            <?php foreach ([['Увидели пейволл', $pw['shown']], ['Нажали «Подписаться»', $pw['clicks']], ['Оплатили подписку', $pw['buyers']]] as [$label, $value]): ?>
                <div class="funnel-step"><span><?= $this->e($label) ?></span><div class="hbar-track"><div class="hbar-fill" style="width: <?= max(1, round($value / $base * 100)) ?>%"></div></div><span class="hbar-value"><?= $this->number($value) ?></span></div>
            <?php endforeach; ?>
        </div>
        <p class="muted small" style="margin-top:1rem">Клик → показ: <?= $this->e(\App\Support\Format::percent((float) $pw['click_rate'])) ?>; покупка → показ: <?= $this->e(\App\Support\Format::percent((float) $pw['conversion'], 2)) ?>.</p>
        <?php if ($pw['top_articles']): ?>
            <h3 class="small muted">Где чаще всего упираются в пейволл</h3>
            <table class="table"><tbody><?php foreach ($pw['top_articles'] as $a): ?><tr><td><a href="/admin/articles/<?= (int) $a['id'] ?>/edit"><?= $this->e($this->truncate((string) $a['title'], 60)) ?></a></td><td class="num"><?= (int) $a['shown'] ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</section>

<section class="two-col" style="margin-top:1.25rem">
    <div class="card-box">
        <h2 class="chart-title" style="padding:1rem 1.1rem 0">Топ статей</h2>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Статья</th><th class="num">Просмотры</th><th class="num">Дочитали</th><th class="num">Комм.</th></tr></thead>
            <tbody>
            <?php foreach ($stats['topArticles'] as $a): ?>
                <tr><td><a href="/news/<?= $this->e($a['slug']) ?>" target="_blank"><?= $this->e($this->truncate((string) $a['title'], 70)) ?></a><?= $a['access'] !== 'free' ? ' <span class="pill">платная</span>' : '' ?></td>
                    <td class="num"><?= $this->number((int) $a['views']) ?></td>
                    <td class="num"><?= (int) $a['views'] > 0 ? $this->e(\App\Support\Format::percent($a['completed'] / $a['views'] * 100, 0)) : '—' ?></td>
                    <td class="num"><?= (int) $a['comments_count'] ?></td></tr>
            <?php endforeach; ?>
            <?php if ($stats['topArticles'] === []): ?><tr><td colspan="4" class="muted">Просмотров за период ещё не было.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php if ($finance && !empty($stats['plans'])): ?>
    <div class="card-box">
        <h2 class="chart-title" style="padding:1rem 1.1rem 0">Подписчики по тарифам</h2>
        <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($stats['plans'] as $p): ?><tr><td><?= $this->e($p['name']) ?></td><td class="num"><?= (int) $p['count'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
    <?php endif; ?>
</section>

<script type="application/json" id="dash-data"><?= $this->json($chartData) ?></script>
<?php $this->start('scripts'); ?>
<script src="<?= $this->asset('js/charts.js') ?>" defer></script>
<?php $this->end(); ?>
