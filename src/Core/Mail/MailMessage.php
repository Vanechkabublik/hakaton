<?php

declare(strict_types=1);

namespace App\Core\Mail;

final class MailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text = '',
        public readonly string $toName = '',
        /** Ссылка отписки (заголовок List-Unsubscribe) для рассылок и дайджестов */
        public readonly ?string $unsubscribeUrl = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'subject' => $this->subject,
            'html' => $this->html,
            'text' => $this->text,
            'to_name' => $this->toName,
            'unsubscribe_url' => $this->unsubscribeUrl,
        ];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['to'],
            (string) $data['subject'],
            (string) $data['html'],
            (string) ($data['text'] ?? ''),
            (string) ($data['to_name'] ?? ''),
            isset($data['unsubscribe_url']) ? (string) $data['unsubscribe_url'] : null,
        );
    }
}
