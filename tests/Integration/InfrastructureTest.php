<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Database;
use App\Core\Queue\Job;
use App\Core\Queue\Queue;
use App\Core\Queue\Worker;
use App\Core\Schedule\Schedule;
use App\Core\Schedule\ScheduledTask;
use App\Core\Schedule\Scheduler;
use App\Core\Logger;
use App\Core\Security\Crypto;
use App\Core\Security\RateLimiter;
use App\Settings\SettingsService;
use Tests\Support\IntegrationTestCase;

final class RecordingJob implements Job
{
    /** @var list<array<string,mixed>> */
    public static array $handled = [];
    public static bool $fail = false;

    public function handle(array $payload): void
    {
        if (self::$fail) {
            throw new \RuntimeException('boom');
        }
        self::$handled[] = $payload;
    }
}

final class CountingTask implements ScheduledTask
{
    public static int $runs = 0;

    public function name(): string
    {
        return 'test:counting';
    }

    public function schedule(): Schedule
    {
        return Schedule::everyMinutes(10);
    }

    public function run(): void
    {
        ++self::$runs;
    }
}

final class InfrastructureTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingJob::$handled = [];
        RecordingJob::$fail = false;
        CountingTask::$runs = 0;
    }

    // ---- очередь -----------------------------------------------------------------------------

    public function testQueueRunsJobsInOrderAndRemovesThem(): void
    {
        $queue = $this->c->get(Queue::class);
        $queue->push(RecordingJob::class, ['n' => 1]);
        $queue->push(RecordingJob::class, ['n' => 2]);

        $this->assertSame(2, $this->c->get(Worker::class)->drain());

        $this->assertSame([['n' => 1], ['n' => 2]], RecordingJob::$handled);
        $this->assertSame(0, $queue->pendingCount());
    }

    public function testDelayedJobWaitsForItsTime(): void
    {
        $this->c->get(Queue::class)->push(RecordingJob::class, ['n' => 1], 3600);

        $this->assertSame(0, $this->c->get(Worker::class)->drain());
        $this->assertSame(1, $this->c->get(Queue::class)->pendingCount());
    }

    public function testFailedJobIsRetriedWithBackoffThenParked(): void
    {
        $queue = $this->c->get(Queue::class);
        $queue->push(RecordingJob::class, [], 0, 'default', 2);
        RecordingJob::$fail = true;
        $worker = $this->c->get(Worker::class);

        $worker->drain();
        $this->assertSame(1, $queue->pendingCount(), 'После первой ошибки задача ждёт повтора');
        $this->assertSame(0, $worker->drain(), 'Повтор отложен (backoff), сразу не выполняется');

        $this->db->execute('UPDATE jobs SET available_at = now() - interval \'1 second\'');
        $worker->drain();

        $this->assertSame(0, $queue->pendingCount());
        $this->assertSame(1, $queue->failedCount(), 'После исчерпания попыток задача помечается проваленной');
        $this->assertStringContainsString('boom', (string) $this->db->fetchValue('SELECT last_error FROM jobs'));

        RecordingJob::$fail = false;
        $this->assertSame(1, $queue->retryFailed());
        $worker->drain();
        $this->assertCount(1, RecordingJob::$handled);
    }

    public function testTwoWorkersNeverTakeTheSameJob(): void
    {
        $queue = $this->c->get(Queue::class);
        $queue->push(RecordingJob::class, ['n' => 1]);

        $first = $queue->reserve();
        $second = $queue->reserve();

        $this->assertNotNull($first);
        $this->assertNull($second, 'Зарезервированную задачу второй воркер не видит');
    }

    // ---- планировщик --------------------------------------------------------------------------

    public function testSchedulerRunsDueTaskOncePerInterval(): void
    {
        $scheduler = new Scheduler($this->c, $this->c->get(Database::class), $this->c->get(Logger::class));
        $scheduler->add(CountingTask::class);

        $this->assertSame(['test:counting'], $scheduler->runDue());
        $this->assertSame([], $scheduler->runDue(), 'Интервал ещё не прошёл');
        $this->assertSame(1, CountingTask::$runs);

        $this->db->execute("UPDATE scheduled_runs SET last_run_at = now() - interval '11 minutes'");
        $this->assertSame(['test:counting'], $scheduler->runDue());
        $this->assertSame(2, CountingTask::$runs);

        $this->assertSame(['test:counting'], $scheduler->runDue(true), '--force запускает всё');
    }

    public function testApplicationRegistersAllScheduledTasks(): void
    {
        $names = array_column($this->c->get(Scheduler::class)->status(), 'name');

        foreach (['articles:publish-scheduled', 'subscriptions:renew', 'subscriptions:reminders', 'payments:sync-stale', 'maintenance:cleanup'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    // ---- ограничение частоты ------------------------------------------------------------------

    public function testRateLimiterBlocksAfterLimitAndExpires(): void
    {
        $limiter = $this->c->get(RateLimiter::class);

        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $results[] = $limiter->attempt('test:key', 3, 60);
        }
        $this->assertSame([true, true, true, false, false], $results);
        $this->assertGreaterThan(0, $limiter->retryAfter('test:key'));

        $this->db->execute("UPDATE rate_limits SET expires_at = now() - interval '1 second'");
        $this->assertTrue($limiter->attempt('test:key', 3, 60), 'Окно истекло — счётчик сброшен');
    }

    public function testRateLimiterKeysAreIndependent(): void
    {
        $limiter = $this->c->get(RateLimiter::class);
        $limiter->attempt('a', 1, 60);

        $this->assertFalse($limiter->attempt('a', 1, 60));
        $this->assertTrue($limiter->attempt('b', 1, 60));
    }

    public function testRouteRateLimitReturns429WithRetryAfter(): void
    {
        $statuses = [];
        $last = null;
        for ($i = 0; $i < 12; ++$i) {
            $last = $this->post('/forgot-password', ['email' => 'nobody@example.test'], '/forgot-password');
            $statuses[] = $last->status();
        }

        $this->assertContains(429, $statuses);
        $limited = array_search(429, $statuses, true);
        $this->assertGreaterThanOrEqual(10, $limited, 'Лимит маршрута — 10 запросов в час');
    }

    // ---- настройки и секреты ----------------------------------------------------------------

    public function testSecretSettingsAreEncryptedAtRest(): void
    {
        $settings = $this->c->get(SettingsService::class);

        $settings->set('yookassa.secret_key', 'live_SuperSecretKey123');

        $stored = (string) $this->db->fetchValue("SELECT value FROM settings WHERE key = 'yookassa.secret_key'");
        $this->assertStringStartsWith('enc1:', $stored);
        $this->assertStringNotContainsString('SuperSecretKey', $stored);
        $this->assertSame('live_SuperSecretKey123', $settings->get('yookassa.secret_key'));
        $this->assertTrue($this->c->get(Crypto::class)->isEncrypted($stored));
    }

    public function testPlainSettingsAreStoredAsIs(): void
    {
        $this->c->get(SettingsService::class)->set('site.name', 'Мой сайт');

        $this->assertSame('Мой сайт', $this->db->fetchValue("SELECT value FROM settings WHERE key = 'site.name'"));
    }

    public function testSettingsFallBackToDefaultsWhenNotStored(): void
    {
        $settings = $this->c->get(SettingsService::class);

        $this->assertSame(3, $settings->int('paywall.free_articles'));
        $this->assertTrue($settings->bool('billing.recurring_enabled'));
        $this->assertFalse($settings->has('yookassa.shop_id'));
    }

    public function testAdminSettingsPageNeverRendersSecretValues(): void
    {
        $this->c->get(SettingsService::class)->set('yookassa.secret_key', 'live_SuperSecretKey123');
        $admin = $this->fx->user(['email' => 'cfg@example.test', 'role' => 'admin']);
        $secret = (new \App\Auth\Totp())->generateSecret();
        $this->db->update('users', ['totp_secret' => $this->c->get(Crypto::class)->encrypt($secret), 'totp_enabled_at' => new \DateTimeImmutable(), 'totp_last_step' => 0], ['id' => $admin->id]);
        $this->loginAs('cfg@example.test', \Tests\Support\Fixtures::PASSWORD);
        $this->post('/login/two-factor', ['code' => (new \App\Auth\Totp())->code($secret)], '/login/two-factor');

        $page = $this->get('/admin/settings');

        $this->assertSame(200, $page->status());
        $this->assertStringNotContainsString('SuperSecretKey', $page->body(), 'Секреты в форму не выводятся');
        $this->assertStringNotContainsString('enc1:', $page->body());
    }

    // ---- аудит ----------------------------------------------------------------------------------

    public function testLoginsAreAudited(): void
    {
        $this->fx->user(['email' => 'audit@example.test']);

        $this->loginAs('audit@example.test', 'WrongPass999');
        $this->loginAs('audit@example.test', \Tests\Support\Fixtures::PASSWORD);

        $actions = $this->db->fetchColumn('SELECT action FROM audit_log ORDER BY id');
        $this->assertContains('auth.login_failed', $actions);
        $this->assertContains('auth.login', $actions);
    }
}
