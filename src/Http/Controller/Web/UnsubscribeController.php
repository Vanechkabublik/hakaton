<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Seo\SeoService;
use App\User\UserRepository;

/** Отписка по ссылке из письма без входа в аккаунт (+ One-Click по RFC 8058). */
final class UnsubscribeController extends Controller
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SeoService $seo,
    ) {
    }

    public function show(string $token): Response
    {
        $user = $this->users->findByUnsubscribeToken($token) ?? $this->notFound();
        $this->meta($this->seo->page('Настройки рассылок', '', '/unsubscribe/' . $token, false));

        return $this->view('web/unsubscribe', ['subject' => $user, 'token' => $token]);
    }

    public function update(Request $request, string $token): Response
    {
        $user = $this->users->findByUnsubscribeToken($token) ?? $this->notFound();
        if ($request->input('List-Unsubscribe') === 'One-Click') {
            $this->users->update($user->id, ['digest_frequency' => 'none', 'marketing_opt_in' => false]);

            return Response::text('OK');
        }
        $frequency = $request->string('digest_frequency', 'none');
        $this->users->update($user->id, [
            'digest_frequency' => in_array($frequency, ['none', 'daily', 'weekly'], true) ? $frequency : 'none',
            'marketing_opt_in' => $request->bool('marketing'),
            'notify_replies' => $request->bool('replies'),
            'notify_authors' => $request->bool('authors'),
        ]);
        if ($request->bool('unsubscribe_all')) {
            $this->users->update($user->id, ['digest_frequency' => 'none', 'marketing_opt_in' => false, 'notify_replies' => false, 'notify_authors' => false]);
        }
        $this->flash('success', 'Настройки сохранены');

        return $this->redirect('/unsubscribe/' . $token);
    }
}
