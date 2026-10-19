<?php

declare(strict_types=1);

namespace App\Newsletter;

use App\Core\Database\Entity;
use DateTimeImmutable;

final class Campaign extends Entity
{
    public int $id = 0;
    public string $name = '';
    public string $subject = '';
    public string $preheader = '';
    public string $bodyHtml = '';
    /** @var array<string,mixed> */
    public array $segment = [];
    public string $status = 'draft';
    public ?DateTimeImmutable $scheduledAt = null;
    public ?DateTimeImmutable $startedAt = null;
    public ?DateTimeImmutable $finishedAt = null;
    public int $totalCount = 0;
    public int $sentCount = 0;
    public int $failedCount = 0;
    public ?int $createdBy = null;
    public DateTimeImmutable $createdAt;
    public DateTimeImmutable $updatedAt;

    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'Черновик',
            'scheduled' => 'Запланирована',
            'sending' => 'Отправляется',
            'sent' => 'Отправлена',
            'canceled' => 'Отменена',
            default => $this->status,
        };
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled'], true);
    }

    public function progressPercent(): int
    {
        return $this->totalCount > 0 ? (int) min(100, round(($this->sentCount + $this->failedCount) / $this->totalCount * 100)) : 0;
    }
}
