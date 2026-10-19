<?php

declare(strict_types=1);

namespace App\Notification;

use App\Core\Queue\Job;

final class SendPushJob implements Job
{
    public function __construct(private readonly PushService $push)
    {
    }

    public function handle(array $payload): void
    {
        $this->push->sendToUser(
            (int) $payload['user_id'],
            (string) $payload['title'],
            (string) $payload['body'],
            (string) $payload['url'],
            isset($payload['tag']) ? (string) $payload['tag'] : null,
        );
    }
}
