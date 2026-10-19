<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\PasswordPolicy;
use App\Core\Config;
use App\Core\Security\Crypto;
use App\Core\Security\PasswordHasher;
use App\Paywall\BotDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private function crypto(string $key = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='): Crypto
    {
        return new Crypto(new Config(['APP_KEY' => $key, 'APP_ENV' => 'production'], '/tmp'));
    }

    public function testEncryptDecryptRoundTrip(): void
    {
        $crypto = $this->crypto();
        $encrypted = $crypto->encrypt('секретный ключ ЮKassa');

        $this->assertTrue($crypto->isEncrypted($encrypted));
        $this->assertStringNotContainsString('ЮKassa', $encrypted);
        $this->assertSame('секретный ключ ЮKassa', $crypto->decrypt($encrypted));
    }

    public function testEncryptionIsRandomized(): void
    {
        $crypto = $this->crypto();
        $this->assertNotSame($crypto->encrypt('x'), $crypto->encrypt('x'));
    }

    public function testTamperedOrForeignCiphertextIsRejected(): void
    {
        $crypto = $this->crypto();
        $encrypted = $crypto->encrypt('secret');
        $tampered = substr($encrypted, 0, -3) . (substr($encrypted, -3) === 'AAA' ? 'BBB' : 'AAA');

        $this->assertNull($crypto->decrypt($tampered));
        $this->assertNull($crypto->decrypt('enc1:мусор'));
        $this->assertNull($crypto->decrypt('plain'));
        $otherKey = $this->crypto('base64:' . base64_encode(str_repeat('K', 32)));
        $this->assertNull($otherKey->decrypt($encrypted));
    }

    public function testProductionRequiresStrongKey(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->crypto('short');
    }

    public function testSignedTokenRoundTripAndTamper(): void
    {
        $crypto = $this->crypto();
        $token = $crypto->signedToken('preview:42', 3600);

        $this->assertSame('preview:42', $crypto->readSignedToken($token));
        $this->assertNull($crypto->readSignedToken($token . 'x'));
        $this->assertNull($crypto->readSignedToken('garbage'));
        $this->assertNull($this->crypto('base64:' . base64_encode(str_repeat('K', 32)))->readSignedToken($token));
    }

    public function testExpiredSignedTokenIsRejected(): void
    {
        $crypto = $this->crypto();
        $this->assertNull($crypto->readSignedToken($crypto->signedToken('x', -10)));
        $this->assertSame('forever', $crypto->readSignedToken($crypto->signedToken('forever')));
    }

    public function testTokenHelpers(): void
    {
        $token = Crypto::token(16);
        $this->assertSame(32, strlen($token));
        $this->assertNotSame($token, Crypto::token(16));
        $this->assertSame(hash('sha256', 'abc'), Crypto::hashToken('abc'));
    }

    public function testPasswordHashing(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Passw0rd123');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($hasher->verify('Passw0rd123', $hash));
        $this->assertFalse($hasher->verify('passw0rd123', $hash));
        $this->assertFalse($hasher->needsRehash($hash));
    }

    /** @return array<string,array{string,string,bool}> */
    public static function passwords(): array
    {
        return [
            'нормальный' => ['Passw0rd123', 'a@b.co', true],
            'кириллица с цифрой' => ['Парольчик2026', 'a@b.co', true],
            'короткий' => ['abc1', 'a@b.co', false],
            'популярный' => ['password1', 'a@b.co', false],
            'без цифр' => ['просто_буквы', 'a@b.co', false],
            'без букв' => ['1234567890123', 'a@b.co', false],
            'совпадает с email' => ['ivan@example.test', 'ivan@example.test', false],
            'слишком длинный' => [str_repeat('a1', 70), 'a@b.co', false],
        ];
    }

    #[DataProvider('passwords')]
    public function testPasswordPolicy(string $password, string $email, bool $valid): void
    {
        $error = (new PasswordPolicy())->validate($password, $email);
        $valid ? $this->assertNull($error) : $this->assertNotNull($error);
    }

    #[DataProvider('userAgents')]
    public function testBotDetector(string $userAgent, bool $isBot): void
    {
        $this->assertSame($isBot, (new BotDetector())->isBot($userAgent));
    }

    /** @return array<string,array{string,bool}> */
    public static function userAgents(): array
    {
        return [
            'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true],
            'YandexBot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', true],
            'Telegram превью' => ['TelegramBot (like TwitterBot)', true],
            'curl' => ['curl/8.5.0', true],
            'пустой UA' => ['', true],
            'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36', false],
            'Safari iOS' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', false],
            'Яндекс.Браузер' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 YaBrowser/24.6 Safari/537.36', false],
        ];
    }
}
