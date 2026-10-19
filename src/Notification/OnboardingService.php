<?php

declare(strict_types=1);

namespace App\Notification;

use App\Billing\SubscriptionRepository;
use App\Core\Config;
use App\Core\Database\Database;
use App\User\User;
use App\User\UserRepository;

/**
 * Онбординг-серия для новых пользователей:
 *  1 — приветствие (сразу после подтверждения email);
 *  2 — через 2 дня: возможности сайта (закладки, темы, авторы);
 *  3 — через 5 дней: предложение подписки (если подписки ещё нет).
 */
final class OnboardingService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subs,
        private readonly MailService $mail,
        private readonly Config $config,
    ) {
    }

    public function welcome(User $user): void
    {
        if ($user->onboardingStep >= 1 || !$user->isEmailVerified()) {
            return;
        }
        $this->mail->queue($user->email, 'Добро пожаловать в ' . $this->siteHost(), 'onboarding-welcome', [
            'name' => $user->firstName(),
            'url' => $this->config->appUrl() . '/',
            'accountUrl' => $this->config->appUrl() . '/account/notifications',
        ], $user);
        $this->users->update($user->id, ['onboarding_step' => 1]);
    }

    /** Выполняется планировщиком каждый час. Возвращает количество отправленных писем. */
    public function processDue(): int
    {
        $sent = 0;
        $rows = $this->db->fetchAll(
            "SELECT id FROM users
              WHERE status = 'active' AND email_verified_at IS NOT NULL AND onboarding_step < 3
                AND email_verified_at > now() - interval '30 days'
              ORDER BY id LIMIT 500"
        );
        foreach ($rows as $row) {
            $user = $this->users->find((int) $row['id']);
            if ($user === null || $user->emailVerifiedAt === null) {
                continue;
            }
            $age = time() - $user->emailVerifiedAt->getTimestamp();
            if ($user->onboardingStep === 0) {
                $this->welcome($user);
                ++$sent;
            } elseif ($user->onboardingStep === 1 && $age >= 2 * 86400) {
                $this->mail->queue($user->email, 'Как получить от сайта максимум', 'onboarding-tips', [
                    'name' => $user->firstName(),
                    'url' => $this->config->appUrl() . '/',
                    'settingsUrl' => $this->config->appUrl() . '/account/notifications',
                ], $user);
                $this->users->update($user->id, ['onboarding_step' => 2]);
                ++$sent;
            } elseif ($user->onboardingStep === 2 && $age >= 5 * 86400) {
                if ($this->subs->latestForUser($user->id) === null) {
                    $this->mail->queue($user->email, 'Подписка: всё, что мы пишем, без ограничений', 'onboarding-subscribe', [
                        'name' => $user->firstName(),
                        'url' => $this->config->appUrl() . '/subscribe?utm_source=onboarding&utm_medium=email',
                    ], $user);
                    ++$sent;
                }
                $this->users->update($user->id, ['onboarding_step' => 3]);
            }
        }

        return $sent;
    }

    private function siteHost(): string
    {
        return (string) parse_url($this->config->appUrl(), PHP_URL_HOST);
    }
}
