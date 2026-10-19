<?php

declare(strict_types=1);

namespace App\Auth;

use App\Audit\AuditLog;
use App\Core\Http\ValidationException;
use App\Core\Security\Crypto;
use App\Core\Security\RateLimiter;
use App\Core\Security\Session;
use App\Settings\SettingsService;
use App\User\User;
use App\User\UserRepository;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Настройка и проверка TOTP, коды восстановления, сброс администратором. */
final class TwoFactorService
{
    private const SETUP_KEY = 'totp_setup';
    private const MAX_FAILURES = 5;
    private const WINDOW = 600;

    public function __construct(
        private readonly Totp $totp,
        private readonly UserRepository $users,
        private readonly Crypto $crypto,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly Session $session,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Начать настройку: секрет хранится в сессии (зашифрованным) до подтверждения кодом.
     *
     * @return array{secret:string,uri:string,qr:string}
     */
    public function beginSetup(User $user): array
    {
        $stored = $this->session->get(self::SETUP_KEY);
        $secret = is_string($stored) ? $this->crypto->decrypt($stored) : null;
        if ($secret === null) {
            $secret = $this->totp->generateSecret();
            $this->session->set(self::SETUP_KEY, $this->crypto->encrypt($secret));
        }
        $uri = $this->totp->uri($secret, $user->email, $this->settings->siteName());

        return ['secret' => $secret, 'uri' => $uri, 'qr' => $this->qrSvg($uri)];
    }

    /**
     * Подтвердить настройку первым кодом из приложения. Возвращает коды восстановления (показываются один раз).
     *
     * @return list<string>
     * @throws ValidationException
     */
    public function confirmSetup(User $user, string $code): array
    {
        $this->guardRate($user);
        $stored = $this->session->get(self::SETUP_KEY);
        $secret = is_string($stored) ? $this->crypto->decrypt($stored) : null;
        if ($secret === null) {
            throw new ValidationException(['code' => 'Сессия настройки истекла. Начните заново.']);
        }
        $step = $this->totp->verify($secret, $code);
        if ($step === null) {
            $this->limiter->hit($this->rateKey($user), self::WINDOW);
            throw new ValidationException(['code' => 'Неверный код. Проверьте время на телефоне и попробуйте ещё раз.']);
        }
        $codes = $this->generateRecoveryCodes();
        $this->users->update($user->id, [
            'totp_secret' => $this->crypto->encrypt($secret),
            'totp_enabled_at' => new \DateTimeImmutable(),
            'totp_last_step' => $step,
            'totp_recovery' => array_map(static fn (string $c): string => Crypto::hashToken($c), $codes),
        ]);
        $this->session->forget(self::SETUP_KEY);
        $this->limiter->clear($this->rateKey($user));
        $this->audit->record('2fa.enabled', $user->id, 'user', $user->id);

        return $codes;
    }

    /** Проверить код TOTP или код восстановления при входе. */
    public function verify(User $user, string $code): bool
    {
        $this->guardRate($user);
        $code = trim($code);
        $secret = $user->totpSecret !== null ? $this->crypto->decrypt($user->totpSecret) : null;
        if ($secret === null) {
            return false;
        }

        $step = $this->totp->verify($secret, $code);
        if ($step !== null) {
            $last = (int) $this->currentLastStep($user);
            if ($step <= $last) {
                // повторное использование кода
                $this->limiter->hit($this->rateKey($user), self::WINDOW);

                return false;
            }
            $this->users->update($user->id, ['totp_last_step' => $step]);
            $this->limiter->clear($this->rateKey($user));

            return true;
        }
        if ($this->consumeRecoveryCode($user, $code)) {
            $this->audit->record('2fa.recovery_used', $user->id, 'user', $user->id);
            $this->limiter->clear($this->rateKey($user));

            return true;
        }
        $this->limiter->hit($this->rateKey($user), self::WINDOW);
        $this->audit->record('2fa.failed', $user->id, 'user', $user->id);

        return false;
    }

    public function disable(User $user, ?int $byUserId = null): void
    {
        $this->users->update($user->id, [
            'totp_secret' => null,
            'totp_enabled_at' => null,
            'totp_last_step' => 0,
            'totp_recovery' => [],
        ]);
        $this->audit->record($byUserId !== null && $byUserId !== $user->id ? '2fa.reset_by_admin' : '2fa.disabled', $byUserId ?? $user->id, 'user', $user->id);
    }

    /** @return list<string> новые коды восстановления */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->generateRecoveryCodes();
        $this->users->update($user->id, ['totp_recovery' => array_map(static fn (string $c): string => Crypto::hashToken($c), $codes)]);
        $this->audit->record('2fa.recovery_regenerated', $user->id, 'user', $user->id);

        return $codes;
    }

    public function remainingRecoveryCodes(User $user): int
    {
        return count($this->recoveryHashes($user));
    }

    private function guardRate(User $user): void
    {
        if ($this->limiter->tooManyAttempts($this->rateKey($user), self::MAX_FAILURES)) {
            throw new ValidationException(['code' => 'Слишком много попыток. Повторите через ' . ceil($this->limiter->retryAfter($this->rateKey($user)) / 60) . ' мин.']);
        }
    }

    private function rateKey(User $user): string
    {
        return '2fa:' . $user->id;
    }

    private function currentLastStep(User $user): int
    {
        return (int) $this->users->find($user->id)?->totpLastStep();
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; ++$i) {
            $raw = strtolower(Totp::base32Encode(random_bytes(7)));

            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        if (!preg_match('/^[a-z2-7]{5}-?[a-z2-7]{5}$/', $code)) {
            return false;
        }
        if (!str_contains($code, '-')) {
            $code = substr($code, 0, 5) . '-' . substr($code, 5);
        }
        $hash = Crypto::hashToken($code);
        $hashes = $this->recoveryHashes($user);
        $index = array_search($hash, $hashes, true);
        if ($index === false) {
            return false;
        }
        unset($hashes[$index]);
        $this->users->update($user->id, ['totp_recovery' => array_values($hashes)]);

        return true;
    }

    /** @return list<string> */
    private function recoveryHashes(User $user): array
    {
        $row = $this->users->find($user->id);

        return $row === null ? [] : $row->recoveryHashes();
    }

    private function qrSvg(string $uri): string
    {
        $renderer = new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd());
        $svg = (new Writer($renderer))->writeString($uri);

        // убираем XML-декларацию, чтобы вставлять SVG прямо в HTML
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg) ?? $svg;
    }
}
