<?php

declare(strict_types=1);

namespace App\Billing;

use App\Core\Database\Entity;
use App\Support\Format;

final class Plan extends Entity
{
    public int $id = 0;
    public string $code = '';
    public string $name = '';
    public PlanTier $tier = PlanTier::Digital;
    public PlanPeriod $period = PlanPeriod::Month;
    /** Цена в копейках */
    public int $price = 0;
    public int $trialDays = 0;
    public string $description = '';
    /** @var array<int,string> */
    public array $features = [];
    public bool $isActive = true;
    public int $sortOrder = 0;

    public function priceFormatted(): string
    {
        return Format::money($this->price);
    }

    /** Эквивалент в месяц (для сравнения годового и месячного тарифов). */
    public function monthlyPrice(): int
    {
        return (int) round($this->price / $this->period->months());
    }

    public function title(): string
    {
        return $this->tier->label() . ' · ' . $this->period->adjective();
    }

    /** Описание позиции для платёжной системы и чека. */
    public function receiptDescription(): string
    {
        return 'Подписка ' . $this->tier->label() . ' (' . ($this->period === PlanPeriod::Month ? '1 месяц' : '1 год') . ')';
    }
}
