<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Auth\EmailTokenService;
use App\Auth\RememberMeService;
use App\Auth\TwoFactorService;
use App\Billing\PaymentRepository;
use App\Billing\PlanRepository;
use App\Billing\SubscriptionRepository;
use App\Billing\SubscriptionService;
use App\Comment\CommentRepository;
use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\StreamedResponse;
use App\Core\Http\ValidationException;
use App\Core\Security\Session;
use App\Http\AdminController;
use App\Notification\MailService;
use App\User\Role;
use App\User\UserRepository;
use App\User\UserStatus;
use DateTimeImmutable;

/** Пользователи: поиск, роли, блокировка, ручная выдача подписки, экспорт, история. */
final class UserController extends AdminController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionService $subscriptions,
        private readonly SubscriptionRepository $subs,
        private readonly PlanRepository $plans,
        private readonly PaymentRepository $payments,
        private readonly CommentRepository $comments,
        private readonly TwoFactorService $twoFactor,
        private readonly EmailTokenService $tokens,
        private readonly RememberMeService $remember,
        private readonly Session $session,
        private readonly MailService $mail,
        private readonly AuditLog $audit,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'role', 'status', 'subscription']);
        [$page, $offset] = $this->paging($request);
        $result = $this->users->search($filters, self::PER_PAGE, $offset);
        $live = [];
        foreach ($result['items'] as $user) {
            $sub = $this->subs->findLiveForUser($user->id);
            if ($sub !== null) {
                $live[$user->id] = $sub;
            }
        }

        return $this->admin('users/index', [
            'title' => 'Пользователи',
            'section' => 'users',
            'items' => $result['items'],
            'live' => $live,
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'role', 'status', 'subscription']);
        $this->audit->record('users.export', $this->requireUser()->id, null, null, $filters);

        return StreamedResponse::csv('users-' . date('Ymd') . '.csv', function (callable $write) use ($filters): void {
            $write(['ID', 'Email', 'Имя', 'Роль', 'Статус', 'Email подтверждён', 'Регистрация', 'Последний вход', 'Подписка']);
            foreach ($this->users->exportRows($filters) as $row) {
                $write([
                    $row['id'], $row['email'], $row['name'], $row['role'], $row['status'],
                    $row['email_verified_at'] ? 'да' : 'нет', $row['created_at'], $row['last_login_at'], $row['subscription'],
                ]);
            }
        });
    }

    public function show(int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();

        return $this->admin('users/show', [
            'title' => $subject->name,
            'section' => 'users',
            'subject' => $subject,
            'live' => $this->subs->findLiveForUser($id),
            'subscriptions' => $this->subs->historyForUser($id),
            'payments' => $this->payments->forUser($id, 30),
            'plans' => $this->plans->all(),
            'comments' => $this->comments->forUser($id, 5, 0),
            'audit' => $this->audit->forUser($id, 40),
            'roles' => Role::cases(),
        ]);
    }

    public function create(): Response
    {
        return $this->admin('users/create', ['title' => 'Новый сотрудник', 'section' => 'users', 'roles' => [Role::Author, Role::Editor, Role::Admin, Role::Reader]]);
    }

    /** Создать пользователя без пароля и отправить приглашение со ссылкой для установки пароля. */
    public function store(Request $request): Response
    {
        $admin = $this->requireUser();
        $data = $this->validate($request, ['email' => 'required|email', 'name' => 'required|string|min:2|max:120', 'role' => 'required|in:reader,author,editor,admin'], ['email' => 'Email', 'name' => 'Имя', 'role' => 'Роль']);
        if ($this->users->emailExists((string) $data['email'])) {
            throw new ValidationException(['email' => 'Пользователь с таким email уже есть']);
        }
        $user = $this->users->createUser([
            'email' => mb_strtolower((string) $data['email']),
            'name' => (string) $data['name'],
            'role' => (string) $data['role'],
            'email_verified' => true,
            'digest_frequency' => 'none',
        ]);
        if ($user->isStaff()) {
            $this->users->ensureSlug($user);
        }
        $token = $this->tokens->create($user, EmailTokenService::RESET, [], 3 * 86400);
        $this->mail->queue($user->email, 'Приглашение в редакцию', 'invite', [
            'name' => $user->name,
            'inviter' => $admin->name,
            'role' => $user->role->label(),
            'url' => $this->config->appUrl() . '/reset-password/' . $token,
        ], $user);
        $this->audit->record('user.created', $admin->id, 'user', $user->id, ['role' => $user->role->value]);
        $this->flash('success', 'Пользователь создан, приглашение отправлено на ' . $user->email);

        return $this->redirect('/admin/users/' . $user->id);
    }

    public function role(Request $request, int $id): Response
    {
        $admin = $this->requireUser();
        $subject = $this->users->find($id) ?? $this->notFound();
        $role = Role::tryFrom($request->string('role')) ?? throw new ValidationException(['role' => 'Неизвестная роль']);
        if ($subject->id === $admin->id) {
            throw new \DomainException('Свою роль менять нельзя — попросите другого администратора');
        }
        $this->users->update($subject->id, ['role' => $role->value]);
        if ($role->isStaff()) {
            $this->users->ensureSlug($subject);
        }
        $this->audit->record('user.role_changed', $admin->id, 'user', $subject->id, ['from' => $subject->role->value, 'to' => $role->value]);

        return $this->done($request, 'Роль изменена: ' . $role->label(), '/admin/users/' . $id);
    }

    public function block(Request $request, int $id): Response
    {
        $admin = $this->requireUser();
        $subject = $this->users->find($id) ?? $this->notFound();
        if ($subject->id === $admin->id) {
            throw new \DomainException('Нельзя заблокировать самого себя');
        }
        $this->users->update($subject->id, ['status' => UserStatus::Blocked->value, 'blocked_reason' => mb_substr($request->string('reason'), 0, 500)]);
        $this->session->destroyUserSessions($subject->id, false);
        $this->remember->forgetAll($subject->id);
        $this->audit->record('user.blocked', $admin->id, 'user', $subject->id, ['reason' => $request->string('reason')]);

        return $this->done($request, 'Пользователь заблокирован', '/admin/users/' . $id);
    }

    public function unblock(Request $request, int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();
        $this->users->update($subject->id, ['status' => UserStatus::Active->value, 'blocked_reason' => null]);
        $this->audit->record('user.unblocked', $this->requireUser()->id, 'user', $subject->id);

        return $this->done($request, 'Пользователь разблокирован', '/admin/users/' . $id);
    }

    public function grant(Request $request, int $id): Response
    {
        $admin = $this->requireUser();
        $subject = $this->users->find($id) ?? $this->notFound();
        $plan = $this->plans->find($request->int('plan_id')) ?? throw new ValidationException(['plan_id' => 'Выберите тариф']);
        $untilInput = $request->string('until');
        $until = $untilInput !== ''
            ? new DateTimeImmutable($untilInput . ' 23:59:59')
            : $plan->period->addTo(new DateTimeImmutable());
        $this->subscriptions->grantManual($subject, $plan, $until, $admin);

        return $this->done($request, 'Подписка выдана до ' . \App\Support\Format::date($until), '/admin/users/' . $id);
    }

    public function revoke(Request $request, int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();
        $sub = $this->subs->findLiveForUser($subject->id) ?? throw new \DomainException('У пользователя нет активной подписки');
        $this->subscriptions->revoke($sub, $this->requireUser());

        return $this->done($request, 'Подписка отозвана', '/admin/users/' . $id);
    }

    public function resetTwoFactor(Request $request, int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();
        $this->twoFactor->disable($subject, $this->requireUser()->id);

        return $this->done($request, '2FA сброшена. Пользователю придётся настроить её заново.', '/admin/users/' . $id);
    }

    public function sendReset(Request $request, int $id): Response
    {
        $subject = $this->users->find($id) ?? $this->notFound();
        $token = $this->tokens->create($subject, EmailTokenService::RESET);
        $this->mail->queue($subject->email, 'Восстановление пароля', 'reset-password', [
            'name' => $subject->name,
            'url' => $this->config->appUrl() . '/reset-password/' . $token,
        ], $subject);
        $this->audit->record('user.reset_sent', $this->requireUser()->id, 'user', $subject->id);

        return $this->done($request, 'Ссылка для сброса пароля отправлена', '/admin/users/' . $id);
    }
}
