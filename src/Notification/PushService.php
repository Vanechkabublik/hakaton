<?php

declare(strict_types=1);

namespace App\Notification;

use App\Core\Database\Database;
use App\Core\Logger;
use App\Settings\SettingsService;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;

/** Браузерные push-уведомления (Web Push, VAPID). */
final class PushService
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingsService $settings,
        private readonly Logger $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('push.vapid_public') !== '' && $this->settings->get('push.vapid_private') !== '';
    }

    public function publicKey(): string
    {
        return $this->settings->get('push.vapid_public');
    }

    /** @return array{publicKey:string,privateKey:string} */
    public static function generateKeys(): array
    {
        return VAPID::createVapidKeys();
    }

    public function subscribe(int $userId, string $endpoint, string $p256dh, string $auth, string $userAgent): void
    {
        if (!preg_match('#^https://#i', $endpoint) || strlen($endpoint) > 1000 || $p256dh === '' || $auth === '') {
            throw new \InvalidArgumentException('Некорректная подписка');
        }
        $this->db->execute(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent) VALUES (:u, :e, :p, :a, :ua)
             ON CONFLICT (endpoint) DO UPDATE SET user_id = EXCLUDED.user_id, p256dh = EXCLUDED.p256dh, auth = EXCLUDED.auth,
                 user_agent = EXCLUDED.user_agent, failed_count = 0',
            ['u' => $userId, 'e' => $endpoint, 'p' => $p256dh, 'a' => $auth, 'ua' => mb_substr($userAgent, 0, 500)]
        );
        $this->db->execute('UPDATE users SET notify_push = TRUE WHERE id = :u', ['u' => $userId]);
    }

    public function unsubscribe(int $userId, string $endpoint): void
    {
        $this->db->execute('DELETE FROM push_subscriptions WHERE user_id = :u AND endpoint = :e', ['u' => $userId, 'e' => $endpoint]);
        $this->syncFlag($userId);
    }

    public function unsubscribeAll(int $userId): void
    {
        $this->db->execute('DELETE FROM push_subscriptions WHERE user_id = :u', ['u' => $userId]);
        $this->db->execute('UPDATE users SET notify_push = FALSE WHERE id = :u', ['u' => $userId]);
    }

    public function deviceCount(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = :u', ['u' => $userId]);
    }

    /** Отправить уведомление на все устройства пользователя. Возвращает число доставленных. */
    public function sendToUser(int $userId, string $title, string $body, string $url, ?string $tag = null): int
    {
        if (!$this->isConfigured()) {
            return 0;
        }
        $rows = $this->db->fetchAll('SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = :u', ['u' => $userId]);
        if ($rows === []) {
            return 0;
        }
        $webPush = new WebPush(['VAPID' => [
            'subject' => $this->settings->get('push.subject') ?: 'mailto:' . ($this->settings->get('site.contact_email') ?: 'admin@localhost'),
            'publicKey' => $this->settings->get('push.vapid_public'),
            'privateKey' => $this->settings->get('push.vapid_private'),
        ]], ['TTL' => 3600 * 12], 15);
        $payload = (string) json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $byEndpoint = [];
        foreach ($rows as $row) {
            $byEndpoint[(string) $row['endpoint']] = (int) $row['id'];
            try {
                $webPush->queueNotification(Subscription::create([
                    'endpoint' => (string) $row['endpoint'],
                    'publicKey' => (string) $row['p256dh'],
                    'authToken' => (string) $row['auth'],
                    'contentEncoding' => 'aes128gcm',
                ]), $payload);
            } catch (\Throwable $e) {
                $this->logger->warning('Некорректная push-подписка', ['id' => $row['id'], 'error' => $e->getMessage()]);
            }
        }

        $delivered = 0;
        foreach ($webPush->flush() as $report) {
            $id = $byEndpoint[$report->getEndpoint()] ?? null;
            if ($report->isSuccess()) {
                ++$delivered;
                continue;
            }
            if ($id === null) {
                continue;
            }
            if ($report->isSubscriptionExpired()) {
                $this->db->execute('DELETE FROM push_subscriptions WHERE id = :id', ['id' => $id]);
            } else {
                $this->db->execute('UPDATE push_subscriptions SET failed_count = failed_count + 1 WHERE id = :id', ['id' => $id]);
                $this->db->execute('DELETE FROM push_subscriptions WHERE id = :id AND failed_count >= 5', ['id' => $id]);
            }
        }
        $this->syncFlag($userId);

        return $delivered;
    }

    private function syncFlag(int $userId): void
    {
        $this->db->execute(
            'UPDATE users SET notify_push = EXISTS (SELECT 1 FROM push_subscriptions WHERE user_id = :u) AND notify_push WHERE id = :u',
            ['u' => $userId]
        );
    }
}
