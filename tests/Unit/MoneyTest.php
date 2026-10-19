<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Billing\Money;
use App\Support\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function testToDecimal(): void
    {
        $this->assertSame('299.00', Money::toDecimal(29900));
        $this->assertSame('0.05', Money::toDecimal(5));
        $this->assertSame('1990.50', Money::toDecimal(199050));
    }

    #[DataProvider('decimals')]
    public function testFromDecimalAvoidsFloatErrors(string|int|float $input, int $expected): void
    {
        $this->assertSame($expected, Money::fromDecimal($input));
    }

    /** @return array<string,array{string|int|float,int}> */
    public static function decimals(): array
    {
        return [
            'строка' => ['299.00', 29900],
            'копейки' => ['0.07', 7],
            'float, дающий ошибку округления' => [19.99, 1999],
            'ещё один коварный float' => [0.29, 29],
            'целое — рубли' => [150, 15000],
            'ноль' => ['0.00', 0],
        ];
    }

    #[DataProvider('userInput')]
    public function testFromInputAcceptsRussianFormats(string $input, int $expected): void
    {
        $this->assertSame($expected, Money::fromInput($input));
    }

    /** @return array<string,array{string,int}> */
    public static function userInput(): array
    {
        return [
            'запятая' => ['199,50', 19950],
            'пробелы-разделители' => ['1 299', 129900],
            'неразрывный пробел' => ["2\u{00A0}499,00", 249900],
            'точка' => ['10.5', 1050],
            'пусто' => ['', 0],
        ];
    }

    public function testToInputRoundTrip(): void
    {
        $this->assertSame(19950, Money::fromInput(Money::toInput(19950)));
        $this->assertSame(29900, Money::fromInput(Money::toInput(29900)));
    }

    public function testFormatMoneyForDisplay(): void
    {
        $this->assertSame('299 ₽', str_replace("\u{00A0}", ' ', Format::money(29900)));
        $this->assertSame('199,50 ₽', str_replace("\u{00A0}", ' ', Format::money(19950)));
    }
}
