<?php

declare(strict_types=1);

namespace App\Billing;

enum PaymentKind: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case Extension = 'extension';
    case Upgrade = 'upgrade';
    case Gift = 'gift';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Новая подписка',
            self::Renewal => 'Автопродление',
            self::Extension => 'Продление',
            self::Upgrade => 'Смена тарифа',
            self::Gift => 'Подарочная подписка',
        };
    }
}
