<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Article\Article;
use App\Core\Database\Database;
use App\Core\Http\Request;
use App\Paywall\BotDetector;
use App\Paywall\Visitor;

/** Внутренняя статистика: просмотры, источники, дочитывания и история чтения пользователя. */
final class ViewTracker
{
    private const DEDUP_MINUTES = 30;

    public function __construct(
        private readonly Database $db,
        private readonly BotDetector $bots,
    ) {
    }

    /** Записать просмотр (боты и повторные заходы в течение 30 минут не считаются). */
    public function recordView(Article $article, Visitor $visitor, Request $request): void
    {
        if ($this->bots->isBot($request->userAgent())) {
            return;
        }
        $exists = $this->db->fetchValue(
            'SELECT 1 FROM article_views WHERE article_id = :a AND visitor_id = :v AND created_at > now() - make_interval(mins => :m) LIMIT 1',
            ['a' => $article->id, 'v' => $visitor->id, 'm' => self::DEDUP_MINUTES]
        );
        if ($exists) {
            return;
        }
        $source = TrafficSource::classify(
            $request->referer(),
            $request->host(),
            $request->query('utm_source') !== null ? (string) $request->query('utm_source') : null,
            $request->query('utm_medium') !== null ? (string) $request->query('utm_medium') : null,
        );
        $this->db->insert('article_views', [
            'article_id' => $article->id,
            'user_id' => $visitor->userId,
            'visitor_id' => $visitor->id,
            'source' => $source['source'],
            'referrer_host' => $source['host'],
            'utm_source' => $source['utm'],
        ]);
        $this->db->execute('UPDATE articles SET views_count = views_count + 1 WHERE id = :id', ['id' => $article->id]);

        if ($visitor->userId !== null) {
            $this->db->execute(
                'INSERT INTO reading_history (user_id, article_id) VALUES (:u, :a)
                 ON CONFLICT (user_id, article_id) DO UPDATE SET last_read_at = now()',
                ['u' => $visitor->userId, 'a' => $article->id]
            );
        }
    }

    /**
     * Маяк с клиента: прогресс чтения и признак дочитывания.
     */
    public function recordProgress(int $articleId, Visitor $visitor, int $progress, int $seconds): void
    {
        $progress = max(0, min(100, $progress));
        $completed = $progress >= 90;
        $this->db->execute(
            'UPDATE article_views SET completed = completed OR :c, read_seconds = GREATEST(read_seconds, :s)
              WHERE id = (SELECT id FROM article_views WHERE article_id = :a AND visitor_id = :v ORDER BY id DESC LIMIT 1)',
            ['c' => $completed, 's' => min($seconds, 7200), 'a' => $articleId, 'v' => $visitor->id]
        );
        if ($visitor->userId !== null) {
            $this->db->execute(
                'INSERT INTO reading_history (user_id, article_id, progress, completed) VALUES (:u, :a, :p, :c)
                 ON CONFLICT (user_id, article_id) DO UPDATE SET
                    progress = GREATEST(reading_history.progress, EXCLUDED.progress),
                    completed = reading_history.completed OR EXCLUDED.completed,
                    last_read_at = now()',
                ['u' => $visitor->userId, 'a' => $articleId, 'p' => $progress, 'c' => $completed]
            );
        }
    }
}
