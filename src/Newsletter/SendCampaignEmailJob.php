<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Core\Mail\Mailer;
use App\Core\Queue\Job;
use App\Notification\MailService;
use App\User\UserRepository;

/** Отправка одного письма кампании (персонализация, ссылка отписки). */
final class SendCampaignEmailJob implements Job
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Mailer $mailer,
    ) {
    }

    public function handle(array $payload): void
    {
        $campaignId = (int) $payload['campaign_id'];
        $userId = (int) $payload['user_id'];
        $campaign = $this->campaigns->find($campaignId);
        $user = $this->users->find($userId);
        if ($campaign === null || $campaign->status === 'canceled' || $user === null) {
            $this->campaigns->markDelivery($campaignId, $userId, 'failed', 'canceled');

            return;
        }
        if (!$user->isActive() || !$user->marketingOptIn || !$user->isEmailVerified()) {
            $this->campaigns->markDelivery($campaignId, $userId, 'failed', 'opted_out');

            return;
        }
        try {
            $body = strtr($campaign->bodyHtml, ['{{name}}' => htmlspecialchars($user->firstName(), ENT_QUOTES, 'UTF-8')]);
            $message = $this->mail->render($user->email, $campaign->subject, 'campaign', [
                'body' => $body,
                'preheader' => $campaign->preheader,
            ], $user, true);
            $this->mailer->send($message);
            $this->campaigns->markDelivery($campaignId, $userId, 'sent');
        } catch (\Throwable $e) {
            $this->campaigns->markDelivery($campaignId, $userId, 'failed', $e->getMessage());
            throw $e;
        }
    }
}
