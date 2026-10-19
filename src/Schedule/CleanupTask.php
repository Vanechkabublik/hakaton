<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Audit\AuditLog;
use App\Auth\EmailTokenService;
use App\Auth\RememberMeService;
use App\Billing\GiftRepository;
use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Queue\Queue;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Core\Security\RateLimiter;
use App\Core\Security\SessionStore;

/** Ночная уборка: просроченные токены, сессии, лимиты, старая статистика и логи. */
final class CleanupTask implements ScheduledTask
{
    public function __construct(
        private readonly Database $db,
        private readonly RateLimiter $limiter,
        private readonly SessionStore $sessions,
        private readonly EmailTokenService $tokens,
        private readonly RememberMeService $remember,
        private readonly Queue $queue,
        private readonly AuditLog $audit,
        private readonly GiftRepository $gifts,
        private readonly Config $config,
    ) {
    }

    public function name(): string
    {
        return 'maintenance:cleanup';
    }

    public function schedule(): Schedule
    {
        return Schedule::dailyAt('03:00');
    }

    public function run(): void
    {
        $this->limiter->purgeExpired();
        $this->sessions->gc($this->config->int('SESSION_LIFETIME_MINUTES', 480) * 60);
        $this->tokens->purgeExpired();
        $this->remember->purgeExpired();
        $this->queue->purgeFailed(14);
        $this->gifts->expireOld();
        $this->audit->purgeOlderThan(730);
        $this->db->execute("DELETE FROM paywall_reads WHERE month < (date_trunc('month', now()) - interval '3 months')");
        $this->db->execute("DELETE FROM paywall_events WHERE created_at < now() - interval '180 days'");
        $this->db->execute("DELETE FROM webhook_events WHERE received_at < now() - interval '180 days'");
        $this->db->execute("DELETE FROM jobs WHERE failed_at IS NULL AND created_at < now() - interval '30 days' AND attempts = 0");
        $this->pruneFiles($this->config->storagePath('logs'), 30);
        $this->pruneFiles($this->config->storagePath('mail'), 7);
    }

    private function pruneFiles(string $dir, int $days): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $limit = time() - $days * 86400;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) && basename($file) !== '.gitkeep' && filemtime($file) < $limit) {
                @unlink($file);
            }
        }
    }
}
