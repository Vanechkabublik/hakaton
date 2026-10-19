<?php

declare(strict_types=1);

namespace App\Core\Mail;

/** Выбирает транспорт: SMTP, если он настроен, иначе файл. В тестах можно подставить свой транспорт. */
final class Mailer
{
    private ?Transport $override = null;

    public function __construct(
        private readonly SmtpTransport $smtp,
        private readonly FileTransport $file,
    ) {
    }

    public function useTransport(?Transport $transport): void
    {
        $this->override = $transport;
    }

    public function send(MailMessage $message): void
    {
        $this->transport()->send($message);
    }

    private function transport(): Transport
    {
        if ($this->override !== null) {
            return $this->override;
        }

        return $this->smtp->isConfigured() ? $this->smtp : $this->file;
    }
}
