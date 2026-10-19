<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Секрет из RFC 6238 («12345678901234567890») в base32. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string,array{int,string}> шестизначные коды = последние 6 цифр 8-значных векторов RFC 6238 (SHA-1) */
    public static function rfcVectors(): array
    {
        return [
            'T=59' => [59, '287082'],
            'T=1111111109' => [1111111109, '081804'],
            'T=1111111111' => [1111111111, '050471'],
            'T=1234567890' => [1234567890, '005924'],
            'T=2000000000' => [2000000000, '279037'],
            'T=20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testMatchesRfcVectors(int $time, string $expected): void
    {
        $this->assertSame($expected, (new Totp())->code(self::RFC_SECRET, $time));
    }

    public function testVerifyAcceptsAdjacentWindowAndReturnsStep(): void
    {
        $totp = new Totp();
        $time = 1111111109;
        $step = $totp->verify(self::RFC_SECRET, '081804', 1, $time);
        $this->assertSame($totp->step($time), $step);

        // код предыдущего окна (30 секунд назад) ещё принимается, но возвращает его собственный шаг
        $previous = $totp->code(self::RFC_SECRET, $time - 30);
        $this->assertSame($totp->step($time) - 1, $totp->verify(self::RFC_SECRET, $previous, 1, $time));
    }

    public function testVerifyRejectsOutOfWindowAndGarbage(): void
    {
        $totp = new Totp();
        $time = 1111111109;
        $this->assertNull($totp->verify(self::RFC_SECRET, $totp->code(self::RFC_SECRET, $time - 120), 1, $time));
        $this->assertNull($totp->verify(self::RFC_SECRET, '000000', 1, $time));
        $this->assertNull($totp->verify(self::RFC_SECRET, '12345', 1, $time));
        $this->assertNull($totp->verify(self::RFC_SECRET, 'abcdef', 1, $time));
        $this->assertNull($totp->verify(self::RFC_SECRET, '', 1, $time));
    }

    public function testGeneratedSecretIsValidBase32(): void
    {
        $secret = (new Totp())->generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function testBase32RoundTrip(): void
    {
        foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', "\x00\xff\x10"] as $raw) {
            $this->assertSame($raw, Totp::base32Decode(Totp::base32Encode($raw)));
        }
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
    }

    public function testProvisioningUri(): void
    {
        $uri = (new Totp())->uri(self::RFC_SECRET, 'ivan@example.test', 'Новости Vancodex');
        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . self::RFC_SECRET, $uri);
        $this->assertStringContainsString('issuer=', $uri);
        $this->assertStringContainsString(rawurlencode('ivan@example.test'), $uri);
    }
}
