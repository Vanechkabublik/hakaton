<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Core\Config;
use App\Core\Database\Database;
use DateTimeImmutable;

/** Агрегаты для дашборда админки. Сутки считаются по часовому поясу сайта. */
final class StatsService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /**
     * Все показатели за период $days последних суток (включая сегодня) и сравнение с предыдущим периодом.
     *
     * @return array<string,mixed>
     */
    public function dashboard(int $days, bool $withFinance): array
    {
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
        $tz = $this->config->timezone();
        $today = new DateTimeImmutable('today', new \DateTimeZone($tz));
        $from = $today->modify('-' . ($days - 1) . ' days');
        $prevFrom = $from->modify('-' . $days . ' days');
        $to = $today->modify('+1 day');

        $data = [
            'days' => $days,
            'from' => $from,
            'users' => $this->usersBlock($from, $to, $prevFrom, $tz),
            'traffic' => $this->trafficBlock($from, $to, $prevFrom, $tz),
            'sources' => $this->sources($from, $to),
            'referrers' => $this->referrers($from, $to),
            'topArticles' => $this->topArticles($from, $to),
            'series' => [],
        ];
        $data['series']['users'] = $this->dailyCount('users', $from, $to, $tz);
        $data['series']['views'] = $this->dailyCount('article_views', $from, $to, $tz);
        $data['series']['completed'] = $this->dailyCount('article_views', $from, $to, $tz, 'completed');

        if ($withFinance) {
            $data['finance'] = $this->financeBlock($from, $to, $prevFrom, $tz);
            $data['paywall'] = $this->paywallBlock($from, $to);
            $data['plans'] = $this->planBreakdown();
            $data['series']['revenue'] = $this->dailyRevenue($from, $to, $tz);
            $data['series']['subscriptions'] = $this->dailySubscriptions($from, $to, $tz);
        }

        return $data;
    }

    // ---- блоки -------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function financeBlock(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $prevFrom, string $tz): array
    {
        $revenue = fn (DateTimeImmutable $a, DateTimeImmutable $b): int => (int) $this->db->fetchValue(
            "SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM payments WHERE status = 'succeeded' AND paid_at >= :a AND paid_at < :b",
            ['a' => $a, 'b' => $b]
        );
        $newSubs = fn (DateTimeImmutable $a, DateTimeImmutable $b): int => (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM subscriptions WHERE source IN ('payment','gift') AND started_at >= :a AND started_at < :b",
            ['a' => $a, 'b' => $b]
        );
        $mrr = (int) $this->db->fetchValue(
            "SELECT COALESCE(ROUND(SUM(p.price::numeric / CASE p.period WHEN 'year' THEN 12 ELSE 1 END)), 0)
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id
              WHERE s.source = 'payment' AND s.status IN ('active','past_due') AND s.current_period_end > now()"
        );
        $active = (int) $this->db->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trialing','past_due') AND current_period_end > now()");
        $trial = (int) $this->db->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status = 'trialing' AND current_period_end > now()");
        $churned = (int) $this->db->fetchValue("SELECT COUNT(*) FROM subscriptions WHERE status = 'expired' AND source = 'payment' AND ended_at >= :a AND ended_at < :b", ['a' => $from, 'b' => $to]);
        $refunds = (int) $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM refunds WHERE status = 'succeeded' AND created_at >= :a AND created_at < :b", ['a' => $from, 'b' => $to]);
        $current = $revenue($from, $to);

        return [
            'revenue' => $current,
            'revenue_prev' => $revenue($prevFrom, $from),
            'mrr' => $mrr,
            'arr' => $mrr * 12,
            'active' => $active,
            'trial' => $trial,
            'new_subs' => $newSubs($from, $to),
            'new_subs_prev' => $newSubs($prevFrom, $from),
            'churned' => $churned,
            'refunds' => $refunds,
            'payments' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM payments WHERE status = 'succeeded' AND paid_at >= :a AND paid_at < :b", ['a' => $from, 'b' => $to]),
        ];
    }

    /** @return array<string,mixed> */
    private function usersBlock(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $prevFrom, string $tz): array
    {
        $count = fn (DateTimeImmutable $a, DateTimeImmutable $b): int => (int) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE created_at >= :a AND created_at < :b', ['a' => $a, 'b' => $b]);

        return [
            'new' => $count($from, $to),
            'new_prev' => $count($prevFrom, $from),
            'total' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM users'),
            'verified' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL'),
        ];
    }

    /** @return array<string,mixed> */
    private function trafficBlock(DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $prevFrom, string $tz): array
    {
        $row = fn (DateTimeImmutable $a, DateTimeImmutable $b): array => $this->db->fetchOne(
            "SELECT COUNT(*) AS views, COUNT(DISTINCT visitor_id) AS visitors,
                    COUNT(*) FILTER (WHERE completed) AS completed,
                    COALESCE(AVG(NULLIF(read_seconds, 0)), 0) AS avg_seconds
               FROM article_views WHERE created_at >= :a AND created_at < :b",
            ['a' => $a, 'b' => $b]
        ) ?? ['views' => 0, 'visitors' => 0, 'completed' => 0, 'avg_seconds' => 0];
        $now = $row($from, $to);
        $prev = $row($prevFrom, $from);

        return [
            'views' => (int) $now['views'],
            'views_prev' => (int) $prev['views'],
            'visitors' => (int) $now['visitors'],
            'completed' => (int) $now['completed'],
            'completion_rate' => (int) $now['views'] > 0 ? round($now['completed'] / $now['views'] * 100, 1) : 0.0,
            'avg_seconds' => (int) round((float) $now['avg_seconds']),
        ];
    }

    /** Воронка пейволла: показы → клики по CTA → покупки. @return array<string,mixed> */
    private function paywallBlock(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $shown = (int) $this->db->fetchValue("SELECT COUNT(DISTINCT identity) FROM paywall_events WHERE event = 'shown' AND created_at >= :a AND created_at < :b", ['a' => $from, 'b' => $to]);
        $clicks = (int) $this->db->fetchValue("SELECT COUNT(DISTINCT identity) FROM paywall_events WHERE event = 'cta_click' AND created_at >= :a AND created_at < :b", ['a' => $from, 'b' => $to]);
        $buyers = (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT user_id) FROM payments WHERE status = 'succeeded' AND paywall_article_id IS NOT NULL AND kind <> 'gift' AND paid_at >= :a AND paid_at < :b",
            ['a' => $from, 'b' => $to]
        );
        $topArticles = $this->db->fetchAll(
            "SELECT a.id, a.title, a.slug, COUNT(DISTINCT e.identity) AS shown
               FROM paywall_events e JOIN articles a ON a.id = e.article_id
              WHERE e.event = 'shown' AND e.created_at >= :a AND e.created_at < :b
              GROUP BY a.id ORDER BY shown DESC LIMIT 5",
            ['a' => $from, 'b' => $to]
        );

        return [
            'shown' => $shown,
            'clicks' => $clicks,
            'buyers' => $buyers,
            'click_rate' => $shown > 0 ? round($clicks / $shown * 100, 1) : 0.0,
            'conversion' => $shown > 0 ? round($buyers / $shown * 100, 2) : 0.0,
            'top_articles' => $topArticles,
        ];
    }

    /** @return list<array{source:string,label:string,count:int}> */
    private function sources(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $labels = ['direct' => 'Прямые заходы', 'search' => 'Поисковики', 'social' => 'Соцсети', 'internal' => 'Внутренние переходы', 'email' => 'Почта и рассылки', 'other' => 'Другие сайты'];
        $rows = $this->db->fetchAll(
            'SELECT source, COUNT(*) AS c FROM article_views WHERE created_at >= :a AND created_at < :b GROUP BY source ORDER BY c DESC',
            ['a' => $from, 'b' => $to]
        );

        return array_map(static fn (array $r): array => ['source' => (string) $r['source'], 'label' => $labels[$r['source']] ?? (string) $r['source'], 'count' => (int) $r['c']], $rows);
    }

    /** @return list<array{host:string,count:int}> */
    private function referrers(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAll(
            "SELECT referrer_host, COUNT(*) AS c FROM article_views
              WHERE created_at >= :a AND created_at < :b AND referrer_host IS NOT NULL AND source <> 'internal'
              GROUP BY referrer_host ORDER BY c DESC LIMIT 8",
            ['a' => $from, 'b' => $to]
        );

        return array_map(static fn (array $r): array => ['host' => (string) $r['referrer_host'], 'count' => (int) $r['c']], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function topArticles(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->db->fetchAll(
            "SELECT a.id, a.title, a.slug, a.access, COUNT(v.id) AS views,
                    COUNT(*) FILTER (WHERE v.completed) AS completed,
                    a.comments_count
               FROM article_views v JOIN articles a ON a.id = v.article_id
              WHERE v.created_at >= :a AND v.created_at < :b
              GROUP BY a.id ORDER BY views DESC LIMIT 10",
            ['a' => $from, 'b' => $to]
        );
    }

    /** @return list<array{name:string,count:int}> */
    private function planBreakdown(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT p.name || ' · ' || CASE p.period WHEN 'year' THEN 'год' ELSE 'месяц' END AS name, COUNT(*) AS c
               FROM subscriptions s JOIN plans p ON p.id = s.plan_id
              WHERE s.status IN ('active','trialing','past_due') AND s.current_period_end > now()
              GROUP BY p.id ORDER BY c DESC"
        );

        return array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'count' => (int) $r['c']], $rows);
    }

    // ---- временные ряды ---------------------------------------------------------------------

    /** @return list<array{date:string,value:int}> */
    private function dailyRevenue(DateTimeImmutable $from, DateTimeImmutable $to, string $tz): array
    {
        return $this->series(
            "SELECT (paid_at AT TIME ZONE :tz)::date AS d, SUM(amount - refunded_amount) AS v
               FROM payments WHERE status = 'succeeded' AND paid_at >= :a AND paid_at < :b GROUP BY d",
            $from,
            $to,
            $tz
        );
    }

    /** @return list<array{date:string,value:int}> */
    private function dailySubscriptions(DateTimeImmutable $from, DateTimeImmutable $to, string $tz): array
    {
        return $this->series(
            "SELECT (started_at AT TIME ZONE :tz)::date AS d, COUNT(*) AS v
               FROM subscriptions WHERE source IN ('payment','gift') AND started_at >= :a AND started_at < :b GROUP BY d",
            $from,
            $to,
            $tz
        );
    }

    /** @return list<array{date:string,value:int}> */
    private function dailyCount(string $table, DateTimeImmutable $from, DateTimeImmutable $to, string $tz, ?string $flag = null): array
    {
        $where = $flag !== null ? "AND {$flag}" : '';

        return $this->series(
            "SELECT (created_at AT TIME ZONE :tz)::date AS d, COUNT(*) AS v FROM {$table} WHERE created_at >= :a AND created_at < :b {$where} GROUP BY d",
            $from,
            $to,
            $tz
        );
    }

    /**
     * Ряд по дням с нулями в пустых днях.
     *
     * @return list<array{date:string,value:int}>
     */
    private function series(string $sql, DateTimeImmutable $from, DateTimeImmutable $to, string $tz): array
    {
        $rows = $this->db->fetchAll($sql, ['tz' => $tz, 'a' => $from, 'b' => $to]);
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['d']] = (int) $row['v'];
        }
        $result = [];
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $result[] = ['date' => $key, 'value' => $byDay[$key] ?? 0];
        }

        return $result;
    }
}
