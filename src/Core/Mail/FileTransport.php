<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config;
use App\Core\Logger;

/**
 * Запасной транспорт, пока SMTP не настроен: письмо сохраняется в storage/mail
 * (удобно в разработке — оттуда берётся ссылка подтверждения).
 */
final class FileTransport implements Transport
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $dir = $this->config->storagePath('mail');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = sprintf('%s-%s.html', gmdate('Ymd-His'), preg_replace('/[^a-z0-9@._-]+/i', '_', $message->to));
        $body = "<!-- To: {$message->to}\n     Subject: {$message->subject} -->\n" . $message->html;
        file_put_contents($dir . '/' . $name, $body);
        $this->logger->warning('SMTP не настроен: письмо сохранено в файл', ['to' => $message->to, 'subject' => $message->subject, 'file' => $name]);
    }
}
