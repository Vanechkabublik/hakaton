<?php

declare(strict_types=1);

namespace App\Notification;

use App\Core\Config;
use App\Core\Mail\MailMessage;
use App\Core\Queue\Queue;
use App\Core\View\View;
use App\Settings\SettingsService;
use App\Support\HtmlText;
use App\User\User;

/**
 * Рендер писем из шаблонов templates/mail/*.php и постановка отправки в очередь.
 * Транзакционные письма уходят сразу в очередь; для дайджестов и рассылок добавляется ссылка отписки.
 */
final class MailService
{
    public function __construct(
        private readonly View $view,
        private readonly Queue $queue,
        private readonly Config $config,
        private readonly SettingsService $settings,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function render(string $to, string $subject, string $template, array $data = [], ?User $recipient = null, bool $withUnsubscribe = false): MailMessage
    {
        $unsubscribeUrl = $withUnsubscribe && $recipient !== null
            ? $this->config->appUrl() . '/unsubscribe/' . $recipient->unsubscribeToken
            : null;
        $logo = $this->settings->get('site.logo');
        $html = $this->view->render('mail/' . $template, $data + [
            'subject' => $subject,
            'siteName' => $this->settings->siteName(),
            'siteUrl' => $this->config->appUrl(),
            'logoUrl' => $logo !== '' ? $this->config->appUrl() . '/uploads/' . ltrim($logo, '/') : null,
            'recipient' => $recipient,
            'unsubscribeUrl' => $unsubscribeUrl,
            'settingsUrl' => $this->config->appUrl() . '/account/notifications',
        ]);

        return new MailMessage($to, $subject, $html, HtmlText::toPlain($html), $recipient?->name ?? '', $unsubscribeUrl);
    }

    /** @param array<string,mixed> $data */
    public function queue(string $to, string $subject, string $template, array $data = [], ?User $recipient = null, bool $withUnsubscribe = false): void
    {
        $this->queueMessage($this->render($to, $subject, $template, $data, $recipient, $withUnsubscribe));
    }

    public function queueMessage(MailMessage $message): void
    {
        $this->queue->push(SendMailJob::class, $message->toArray(), 0, 'default', 6);
    }
}
