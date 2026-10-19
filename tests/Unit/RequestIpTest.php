<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestIpTest extends TestCase
{
    public function testUsesRemoteAddrWhenNoProxy(): void
    {
        $request = Request::create('GET', '/', [], ['REMOTE_ADDR' => '203.0.113.5']);

        $this->assertSame('203.0.113.5', $request->ip());
    }

    public function testPrefersClientIpParameterFromCaddy(): void
    {
        // CLIENT_IP — параметр FastCGI от Caddy: за доверенным прокси это адрес из X-Forwarded-For
        $request = Request::create('GET', '/', [], ['REMOTE_ADDR' => '172.18.0.3', 'CLIENT_IP' => '198.51.100.7']);

        $this->assertSame('198.51.100.7', $request->ip());
    }

    public function testForwardedForHeaderFromClientIsNeverTrustedByTheApp(): void
    {
        // Заголовок X-Forwarded-For попадает в HTTP_X_FORWARDED_FOR; доверять ему должен только Caddy, но не приложение
        $request = Request::create('GET', '/', [], ['REMOTE_ADDR' => '203.0.113.5', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6', 'HTTP_CLIENT_IP' => '7.7.7.7']);

        $this->assertSame('203.0.113.5', $request->ip());
    }

    public function testInvalidValuesFallBack(): void
    {
        $this->assertSame('203.0.113.5', Request::create('GET', '/', [], ['REMOTE_ADDR' => '203.0.113.5', 'CLIENT_IP' => 'not-an-ip'])->ip());
        $this->assertSame('2001:db8::1', Request::create('GET', '/', [], ['REMOTE_ADDR' => '2001:db8::1'])->ip());
        $this->assertSame('0.0.0.0', Request::create('GET', '/', [], ['REMOTE_ADDR' => ''])->ip());
    }
}
