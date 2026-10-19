<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Config;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Crypto;

/** Заголовки безопасности и CSP с nonce для inline-скриптов. */
final class SecurityHeaders implements Middleware
{
    private const PAYMENT = 'https://yookassa.ru https://*.yookassa.ru https://yoomoney.ru https://*.yoomoney.ru';
    private const METRIKA = 'https://mc.yandex.ru https://mc.yandex.com https://yastatic.net';
    private const GA = 'https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';

    public function __construct(private readonly Config $config)
    {
    }

    public function handle(Request $request, callable $next, array $args = []): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->withAttribute('csp_nonce', $nonce);
        $response = $next($request);

        $csp = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' " . self::PAYMENT . ' ' . self::METRIKA . ' ' . self::GA,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://avatars.yandex.net https://mc.yandex.ru https://*.google-analytics.com https://*.googletagmanager.com " . self::PAYMENT,
            "font-src 'self' data:",
            "connect-src 'self' " . self::PAYMENT . ' https://mc.yandex.ru https://mc.yandex.com wss://mc.yandex.ru ' . self::GA,
            "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://vk.com https://rutube.ru https://mc.yandex.ru " . self::PAYMENT,
            "media-src 'self'",
            "worker-src 'self'",
            "manifest-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "frame-ancestors 'self'",
        ];
        if (!$response->header('Content-Security-Policy')) {
            $response->withHeader('Content-Security-Policy', implode('; ', $csp));
        }
        $response->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'SAMEORIGIN')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(self)')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        if ($request->isSecure() || str_starts_with($this->config->appUrl(), 'https://')) {
            $response->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (!$response->header('Cache-Control') && str_contains((string) $response->header('Content-Type'), 'text/html')) {
            $response->withHeader('Cache-Control', 'private, no-cache');
        }

        return $response;
    }
}
