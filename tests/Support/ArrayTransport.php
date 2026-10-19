<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Mail\MailMessage;
use App\Core\Mail\Transport;

/** Складывает письма в память вместо отправки. */
final class ArrayTransport implements Transport
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public function send(MailMessage $message): void
    {
        $this->sent[] = $message;
    }

    public function last(): ?MailMessage
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }

    /** @return list<MailMessage> */
    public function to(string $email): array
    {
        return array_values(array_filter($this->sent, static fn (MailMessage $m): bool => strcasecmp($m->to, $email) === 0));
    }

    /** Первая ссылка письма, содержащая указанный фрагмент пути. */
    public function linkIn(MailMessage $message, string $needle): ?string
    {
        if (preg_match_all('#https?://[^\s"\'<>]+#u', $message->html . "\n" . $message->text, $m) < 1) {
            return null;
        }
        foreach ($m[0] as $url) {
            $url = html_entity_decode($url);
            if (str_contains($url, $needle)) {
                return $url;
            }
        }

        return null;
    }
}
