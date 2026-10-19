<?php

declare(strict_types=1);

namespace App\Core\Mail;

interface Transport
{
    /** @throws \RuntimeException если письмо не удалось отправить */
    public function send(MailMessage $message): void;
}
