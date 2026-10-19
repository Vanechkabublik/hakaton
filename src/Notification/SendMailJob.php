<?php

declare(strict_types=1);

namespace App\Notification;

use App\Core\Mail\MailMessage;
use App\Core\Mail\Mailer;
use App\Core\Queue\Job;

final class SendMailJob implements Job
{
    public function __construct(private readonly Mailer $mailer)
    {
    }

    public function handle(array $payload): void
    {
        $this->mailer->send(MailMessage::fromArray($payload));
    }
}
