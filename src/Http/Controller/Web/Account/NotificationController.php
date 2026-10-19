<?php

declare(strict_types=1);

namespace App\Http\Controller\Web\Account;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Engagement\AuthorFollowRepository;
use App\Http\Controller;
use App\Notification\PushService;
use App\Seo\SeoService;
use App\User\UserRepository;

/** Настройки уведомлений: дайджест, ответы, авторы, push. */
final class NotificationController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuthorFollowRepository $follows,
        private readonly PushService $push,
        private readonly SeoService $seo,
    ) {
    }

    public function show(): Response
    {
        $user = $this->requireUser();
        $this->meta($this->seo->page('Уведомления', '', '/account/notifications', false));

        return $this->view('web/account/notifications', [
            'section' => 'notifications',
            'authors' => $this->follows->followedAuthors($user->id),
            'pushAvailable' => $this->push->isConfigured(),
            'pushDevices' => $this->push->deviceCount($user->id),
            'vapid' => $this->push->publicKey(),
        ]);
    }

    public function update(Request $request): Response
    {
        $user = $this->requireUser();
        $frequency = $request->string('digest_frequency', 'none');
        $this->users->update($user->id, [
            'digest_frequency' => in_array($frequency, ['none', 'daily', 'weekly'], true) ? $frequency : 'none',
            'notify_replies' => $request->bool('notify_replies'),
            'notify_authors' => $request->bool('notify_authors'),
            'marketing_opt_in' => $request->bool('marketing_opt_in'),
        ]);
        $this->flash('success', 'Настройки уведомлений сохранены');

        return $this->redirect('/account/notifications');
    }

    public function pushSubscribe(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->push->isConfigured()) {
            throw new \DomainException('Push-уведомления не настроены на сервере');
        }
        $keys = (array) $request->input('keys', []);
        $this->push->subscribe(
            $user->id,
            $request->string('endpoint'),
            (string) ($keys['p256dh'] ?? ''),
            (string) ($keys['auth'] ?? ''),
            $request->userAgent(),
        );

        return $this->json(['ok' => true]);
    }

    public function pushUnsubscribe(Request $request): Response
    {
        $user = $this->requireUser();
        $endpoint = $request->string('endpoint');
        $endpoint !== '' ? $this->push->unsubscribe($user->id, $endpoint) : $this->push->unsubscribeAll($user->id);

        return $this->json(['ok' => true]);
    }
}
