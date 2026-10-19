<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Audit\AuditLog;
use App\Core\Queue\Queue;
use App\Support\HtmlSanitizer;
use App\User\User;
use DateTimeImmutable;

/** Создание и рассылка email-кампаний через очередь. */
final class CampaignService
{
    private const CHUNK = 200;

    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly Queue $queue,
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    public function save(?int $id, array $input, User $admin): int
    {
        $data = [
            'name' => mb_substr(trim((string) ($input['name'] ?? '')), 0, 200),
            'subject' => mb_substr(trim((string) ($input['subject'] ?? '')), 0, 300),
            'preheader' => mb_substr(trim((string) ($input['preheader'] ?? '')), 0, 300),
            'body_html' => $this->sanitizer->clean((string) ($input['body_html'] ?? '')),
            'segment' => [
                'subscription' => in_array($input['segment_subscription'] ?? '', ['all', 'active', 'trial', 'none', 'expired', 'not_subscribed'], true) ? $input['segment_subscription'] : 'all',
                'activity' => in_array($input['segment_activity'] ?? '', ['all', 'active_30', 'inactive_30', 'new_7'], true) ? $input['segment_activity'] : 'all',
            ],
        ];
        if ($data['name'] === '' || $data['subject'] === '' || trim(strip_tags($data['body_html'])) === '') {
            throw new \DomainException('Заполните название, тему и текст письма');
        }
        if ($id === null) {
            $data['created_by'] = $admin->id;
            $id = $this->campaigns->create($data);
        } else {
            $campaign = $this->campaigns->find($id);
            if ($campaign === null || !$campaign->isEditable()) {
                throw new \DomainException('Кампанию нельзя изменить после начала отправки');
            }
            $this->campaigns->update($id, $data);
        }
        $this->audit->record('campaign.saved', $admin->id, 'campaign', $id);

        return $id;
    }

    public function schedule(Campaign $campaign, ?DateTimeImmutable $at, User $admin): void
    {
        if (!$campaign->isEditable()) {
            throw new \DomainException('Кампания уже отправлена');
        }
        $at ??= new DateTimeImmutable();
        $this->campaigns->update($campaign->id, [
            'status' => 'scheduled',
            'scheduled_at' => $at,
            'total_count' => $this->campaigns->countRecipients($campaign->segment),
        ]);
        $this->audit->record('campaign.scheduled', $admin->id, 'campaign', $campaign->id, ['at' => $at->format(DATE_ATOM)]);
    }

    public function cancel(Campaign $campaign, User $admin): void
    {
        if (!in_array($campaign->status, ['scheduled', 'sending'], true)) {
            throw new \DomainException('Нечего отменять');
        }
        $this->campaigns->update($campaign->id, ['status' => 'canceled', 'finished_at' => new DateTimeImmutable()]);
        $this->audit->record('campaign.canceled', $admin->id, 'campaign', $campaign->id);
    }

    /** Шаг планировщика: запуск наступивших кампаний и постановка очередных порций писем. */
    public function dispatch(): int
    {
        foreach ($this->campaigns->dueToStart() as $campaign) {
            $this->campaigns->update($campaign->id, [
                'status' => 'sending',
                'started_at' => new DateTimeImmutable(),
                'total_count' => $this->campaigns->countRecipients($campaign->segment),
            ]);
        }
        $queued = 0;
        foreach ($this->campaigns->sending() as $campaign) {
            $after = $this->campaigns->lastQueuedUserId($campaign->id);
            $chunk = $this->campaigns->recipientChunk($campaign->segment, $after, self::CHUNK);
            if ($chunk === []) {
                if ($this->campaigns->pendingDeliveries($campaign->id) === 0) {
                    $this->campaigns->update($campaign->id, ['status' => 'sent', 'finished_at' => new DateTimeImmutable()]);
                }
                continue;
            }
            $this->campaigns->addDeliveries($campaign->id, $chunk);
            foreach ($chunk as $userId) {
                $this->queue->push(SendCampaignEmailJob::class, ['campaign_id' => $campaign->id, 'user_id' => $userId], 0, 'default', 3);
            }
            $queued += count($chunk);
        }

        return $queued;
    }
}
