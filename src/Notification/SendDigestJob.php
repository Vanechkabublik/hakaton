<?php

declare(strict_types=1);

namespace App\Notification;

use App\Core\Queue\Job;

final class SendDigestJob implements Job
{
    public function __construct(private readonly DigestService $digests)
    {
    }

    public function handle(array $payload): void
    {
        $user = $this->digests->user((int) $payload['user_id']);
        if ($user !== null) {
            $this->digests->send($user, (string) ($payload['frequency'] ?? 'weekly'));
        }
    }
}
