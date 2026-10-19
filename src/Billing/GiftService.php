<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Database;
use App\Notification\MailService;
use App\User\User;
use App\User\UserRepository;

/** Активация подарочных кодов. */
final class GiftService
{
    public function __construct(
        private readonly GiftRepository $gifts,
        private readonly SubscriptionService $subscriptions,
        private readonly UserRepository $users,
        private readonly MailService $mail,
        private readonly Database $db,
    ) {
    }

    /** @throws \DomainException */
    public function redeem(User $user, string $code): Subscription
    {
        $gift = $this->gifts->findByCode($code);
        if ($gift === null) {
            throw new \DomainException('Код не найден. Проверьте, что он введён без ошибок.');
        }
        if ($gift->status === 'redeemed') {
            throw new \DomainException('Этот подарок уже активирован.');
        }
        if (!$gift->isRedeemable()) {
            throw new \DomainException('Срок действия подарка истёк или он ещё не оплачен.');
        }

        $subscription = $this->db->transaction(function () use ($user, $gift): Subscription {
            if (!$this->gifts->markRedeemed($gift->id, $user->id)) {
                throw new \DomainException('Этот подарок уже активирован.');
            }

            return $this->subscriptions->redeemGift($user, $gift);
        });

        if ($gift->buyerId !== null) {
            $buyer = $this->users->find($gift->buyerId);
            if ($buyer !== null) {
                $this->mail->queue($buyer->email, 'Ваш подарок активирован', 'gift-redeemed', [
                    'name' => $buyer->firstName(), 'recipient' => $user->name, 'plan' => $gift->planName,
                ], $buyer);
            }
        }

        return $subscription;
    }
}
