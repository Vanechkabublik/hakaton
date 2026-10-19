<?php

declare(strict_types=1);

namespace App\Billing;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case WaitingForCapture = 'waiting_for_capture';
    case Succeeded = 'succeeded';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает оплаты',
            self::WaitingForCapture => 'Ожидает подтверждения',
            self::Succeeded => 'Оплачен',
            self::Canceled => 'Отменён',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending, self::WaitingForCapture => 'warning',
            self::Succeeded => 'success',
            self::Canceled => 'muted',
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Canceled;
    }
}
