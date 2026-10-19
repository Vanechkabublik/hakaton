<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\PaymentGatewayException;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Mail\Mailer;
use App\Http\AdminController;
use App\Media\ImageException;
use App\Media\MediaService;
use App\Notification\MailService;
use App\Notification\PushService;
use App\Settings\SettingsRegistry;
use App\Settings\SettingsService;

/** Настройки сайта: название и логотип, SEO, SMTP, ключи API, тексты пейволла. */
final class SettingsController extends AdminController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly MediaService $media,
        private readonly Mailer $mailer,
        private readonly MailService $mail,
        private readonly PaymentGateway $gateway,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {
    }

    public function show(?string $group = null): Response
    {
        $group = $group !== null && isset(SettingsRegistry::GROUPS[$group]) ? $group : 'general';
        $definitions = SettingsRegistry::grouped()[$group] ?? [];
        $values = [];
        $secretSet = [];
        foreach ($definitions as $definition) {
            if ($definition->isSecret()) {
                $secretSet[$definition->key] = $this->settings->isSecretSet($definition->key);
            } else {
                $values[$definition->key] = $this->settings->get($definition->key);
            }
        }

        return $this->admin('settings/show', [
            'title' => 'Настройки: ' . SettingsRegistry::GROUPS[$group],
            'section' => 'settings',
            'group' => $group,
            'groups' => SettingsRegistry::GROUPS,
            'definitions' => $definitions,
            'values' => $values,
            'secretSet' => $secretSet,
            'appUrl' => $this->config->appUrl(),
        ]);
    }

    public function save(Request $request, string $group): Response
    {
        $admin = $this->requireUser();
        if (!isset(SettingsRegistry::GROUPS[$group])) {
            $this->notFound();
        }
        $definitions = SettingsRegistry::grouped()[$group] ?? [];
        $keys = array_map(static fn ($d): string => $d->key, $definitions);
        $before = [];
        foreach ($definitions as $d) {
            $before[$d->key] = $d->isSecret() ? '' : $this->settings->get($d->key);
        }
        $this->settings->saveMany($request->all(), $keys, $admin->id);

        // Загрузка и удаление изображений (логотип, картинка для соцсетей)
        foreach ($definitions as $definition) {
            if ($definition->type !== 'image') {
                continue;
            }
            $field = str_replace('.', '__', $definition->key);
            $file = $request->file($field);
            try {
                if ($file !== null) {
                    $old = $this->settings->get($definition->key);
                    $this->settings->set($definition->key, $this->media->uploadBranding($file, str_replace('.', '-', $definition->key)), $admin->id);
                    if ($old !== '' && $this->isOwnFile($old)) {
                        $this->media->deleteFile($old);
                    }
                } elseif ($request->bool($field . '__remove')) {
                    $old = $this->settings->get($definition->key);
                    $this->settings->set($definition->key, '', $admin->id);
                    if ($old !== '' && $this->isOwnFile($old)) {
                        $this->media->deleteFile($old);
                    }
                }
            } catch (ImageException $e) {
                $this->flash('error', $definition->label . ': ' . $e->getMessage());
            }
        }

        $changed = [];
        foreach ($definitions as $d) {
            if (!$d->isSecret() && $d->type !== 'image' && ($before[$d->key] ?? '') !== $this->settings->get($d->key)) {
                $changed[] = $d->key;
            }
        }
        $this->audit->record('settings.updated', $admin->id, 'settings', $group, ['changed' => $changed]);
        $this->flash('success', 'Настройки сохранены');

        return $this->redirect('/admin/settings/' . $group);
    }

    /** Тестовое письмо себе — ошибки SMTP показываются сразу. */
    public function testMail(Request $request): Response
    {
        $admin = $this->requireUser();
        $message = $this->mail->render($admin->email, 'Проверка почты', 'campaign', [
            'body' => '<p>Это тестовое письмо. Если вы его читаете — SMTP настроен правильно.</p>',
            'preheader' => 'Проверка отправки почты',
        ], $admin);
        try {
            $this->mailer->send($message);
            $this->flash('success', 'Тестовое письмо отправлено на ' . $admin->email . ' (если SMTP не настроен — оно сохранено в storage/mail).');
        } catch (\Throwable $e) {
            $this->flash('error', 'Не удалось отправить письмо: ' . $e->getMessage());
        }

        return $this->redirect('/admin/settings/mail');
    }

    public function testYooKassa(): Response
    {
        try {
            $me = $this->gateway->testConnection();
            $this->flash('success', sprintf(
                'ЮKassa подключена: магазин №%s, режим: %s, статус: %s.',
                (string) ($me['account_id'] ?? '—'),
                ($me['test'] ?? false) ? 'тестовый' : 'боевой',
                (string) ($me['status'] ?? '—')
            ));
        } catch (PaymentGatewayException $e) {
            $this->flash('error', 'ЮKassa: ' . $e->getMessage());
        }

        return $this->redirect('/admin/settings/yookassa');
    }

    public function generateVapid(): Response
    {
        $admin = $this->requireUser();
        $keys = PushService::generateKeys();
        $this->settings->set('push.vapid_public', $keys['publicKey'], $admin->id);
        $this->settings->set('push.vapid_private', $keys['privateKey'], $admin->id);
        if ($this->settings->get('push.subject') === '') {
            $this->settings->set('push.subject', $this->config->appUrl(), $admin->id);
        }
        $this->audit->record('settings.vapid_generated', $admin->id);
        $this->flash('success', 'Ключи VAPID сгенерированы. Пользователи, подписанные ранее, должны подписаться заново.');

        return $this->redirect('/admin/settings/push');
    }

    private function isOwnFile(string $path): bool
    {
        return str_starts_with($path, 'branding/');
    }
}
