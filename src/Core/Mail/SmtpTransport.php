<?php

declare(strict_types=1);

namespace App\Core\Mail;

use App\Settings\SettingsService;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/** Отправка через SMTP (PHPMailer). Параметры берутся из настроек админки или окружения. */
final class SmtpTransport implements Transport
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('mail.host') !== '' && $this->settings->get('mail.from_address') !== '';
    }

    public function send(MailMessage $message): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->settings->get('mail.host');
            $mail->Port = $this->settings->int('mail.port', 587);
            $username = $this->settings->get('mail.username');
            $mail->SMTPAuth = $username !== '';
            $mail->Username = $username;
            $mail->Password = $this->settings->get('mail.password');
            $mail->SMTPSecure = match ($this->settings->get('mail.encryption')) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'none' => '',
                default => PHPMailer::ENCRYPTION_STARTTLS,
            };
            $mail->SMTPAutoTLS = $this->settings->get('mail.encryption') !== 'none';
            $mail->Timeout = 20;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(
                $this->settings->get('mail.from_address'),
                $this->settings->get('mail.from_name') ?: $this->settings->siteName()
            );
            $mail->addAddress($message->to, $message->toName);
            $mail->Subject = $message->subject;
            $mail->isHTML(true);
            $mail->Body = $message->html;
            $mail->AltBody = $message->text !== '' ? $message->text : strip_tags($message->html);
            if ($message->unsubscribeUrl !== null) {
                $mail->addCustomHeader('List-Unsubscribe', '<' . $message->unsubscribeUrl . '>');
                $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            }
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');
            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException('SMTP: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
