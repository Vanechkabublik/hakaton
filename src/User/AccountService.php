<?php

declare(strict_types=1);

namespace App\User;

use App\Audit\AuditLog;
use App\Auth\RememberMeService;
use App\Billing\SubscriptionRepository;
use App\Billing\SubscriptionService;
use App\Core\Database\Database;
use App\Core\Http\UploadedFile;
use App\Core\Security\PasswordHasher;
use App\Core\Security\Session;
use App\Media\ImageException;
use App\Media\MediaService;
use App\Notification\PushService;

/** Операции над собственным аккаунтом: профиль, аватар, удаление. */
final class AccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly MediaService $media,
        private readonly Database $db,
        private readonly PasswordHasher $hasher,
        private readonly SubscriptionRepository $subs,
        private readonly SubscriptionService $subscriptions,
        private readonly PushService $push,
        private readonly RememberMeService $remember,
        private readonly Session $session,
        private readonly AuditLog $audit,
    ) {
    }

    /** @throws ImageException */
    public function changeAvatar(User $user, UploadedFile $file): void
    {
        $path = $this->media->uploadAvatar($file, $user->id);
        $this->removeOwnAvatar($user);
        $this->users->update($user->id, ['avatar_path' => $path]);
    }

    public function removeAvatar(User $user): void
    {
        $this->removeOwnAvatar($user);
        $this->users->update($user->id, ['avatar_path' => null]);
    }

    private function removeOwnAvatar(User $user): void
    {
        // внешние аватары (Яндекс) не хранятся у нас
        if ($user->avatarPath !== null && !str_starts_with($user->avatarPath, 'http')) {
            $this->media->deleteFile($user->avatarPath);
        }
    }

    /**
     * Полное удаление аккаунта. Платежи сохраняются обезличенно (бухгалтерский учёт),
     * комментарии остаются без автора (или с замещённым текстом).
     *
     * @throws \DomainException
     */
    public function delete(User $user, ?string $password, bool $eraseComments): void
    {
        if ($user->isStaff()) {
            throw new \DomainException('Сотрудники редакции не могут удалить аккаунт самостоятельно — обратитесь к администратору.');
        }
        if ($user->hasPassword() && !$this->hasher->verify((string) $password, (string) $user->passwordHash)) {
            throw new \DomainException('Пароль указан неверно');
        }
        $live = $this->subs->findLiveForUser($user->id);
        if ($live !== null) {
            $this->subscriptions->forgetPaymentMethod($live);
        }
        $this->db->transaction(function () use ($user, $eraseComments): void {
            $this->push->unsubscribeAll($user->id);
            if ($eraseComments) {
                $this->db->execute("UPDATE comments SET body = '[комментарий удалён автором]' WHERE user_id = :u", ['u' => $user->id]);
            }
            $this->db->execute('UPDATE payments SET customer_email = NULL WHERE user_id = :u', ['u' => $user->id]);
            $this->db->execute('UPDATE gifts SET buyer_name = \'\' WHERE buyer_id = :u', ['u' => $user->id]);
            $this->audit->record('account.deleted', null, 'user', $user->id);
            $this->users->deleteById($user->id);
        });
        $this->removeOwnAvatar($user);
    }
}
