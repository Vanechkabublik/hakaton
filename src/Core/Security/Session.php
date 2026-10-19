<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Серверная сессия поверх таблицы sessions (без глобального состояния ext/session).
 * Ленивая: пока в сессию ничего не записано, cookie не выдаётся и строка в БД не создаётся —
 * поисковые боты и анонимные читатели не создают сессий.
 */
final class Session
{
    public const COOKIE = 'nsid';
    private const TOUCH_INTERVAL = 300;

    /** @var array<string,mixed> */
    private array $data = [];
    private string $id = '';
    private bool $loaded = false;
    private bool $dirty = false;
    private bool $newCookie = false;
    private ?int $lastActivity = null;
    private ?string $destroyId = null;

    public function __construct(
        private readonly SessionStore $store,
        private readonly Config $config,
    ) {
    }

    /** Загрузить сессию по cookie запроса. Вызывается middleware в начале запроса. */
    public function load(Request $request): void
    {
        $this->data = [];
        $this->id = '';
        $this->loaded = true;
        $this->dirty = false;
        $this->newCookie = false;
        $this->lastActivity = null;
        $this->destroyId = null;

        $cookie = $request->cookie(self::COOKIE);
        if ($cookie !== null && preg_match('/^[a-f0-9]{64}$/', $cookie)) {
            $row = $this->store->read(Crypto::hashToken($cookie), $this->maxIdleSeconds());
            if ($row !== null) {
                $this->id = $cookie;
                $this->data = $row['data'];
                $this->lastActivity = $row['last_activity'];
            }
        }
    }

    public function isStarted(): bool
    {
        return $this->id !== '';
    }

    public function id(): string
    {
        return $this->id;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $this->dirty = true;
            }
        }
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    public function flash(string $type, string $message): void
    {
        $flash = $this->get('_flash', []);
        $flash[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flash);
    }

    /** @return list<array{type:string,message:string}> */
    public function pullFlash(): array
    {
        return $this->has('_flash') ? (array) $this->pull('_flash', []) : [];
    }

    /** Запомнить введённые данные формы и ошибки для следующего запроса. */
    public function flashInput(array $input, array $errors = []): void
    {
        unset($input['password'], $input['password_confirmation'], $input['_token'], $input['current_password']);
        $this->set('_old', $input);
        $this->set('_errors', $errors);
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    public function pullInput(): array
    {
        if (!$this->has('_old') && !$this->has('_errors')) {
            return [[], []];
        }

        return [(array) $this->pull('_old', []), (array) $this->pull('_errors', [])];
    }

    /** Новый идентификатор (защита от фиксации сессии) с сохранением данных. */
    public function regenerate(): void
    {
        $this->ensureStarted();
        $this->destroyId = $this->id;
        $this->id = Crypto::token(32);
        $this->newCookie = true;
        $this->dirty = true;
    }

    /** Полностью уничтожить сессию (выход). */
    public function invalidate(): void
    {
        if ($this->id !== '') {
            $this->destroyId = $this->id;
        }
        $this->data = [];
        $this->id = '';
        $this->dirty = false;
        $this->newCookie = false;
    }

    private function ensureStarted(): void
    {
        if ($this->id === '') {
            $this->id = Crypto::token(32);
            $this->newCookie = true;
        }
    }

    /** Сохранить сессию и выдать cookie. Вызывается в конце запроса. */
    public function commit(Request $request, Response $response): void
    {
        if ($this->destroyId !== null) {
            $this->store->delete(Crypto::hashToken($this->destroyId));
            if ($this->id === '') {
                $response->forgetCookie(self::COOKIE);
            }
        }
        if ($this->id === '') {
            return;
        }
        $stale = $this->lastActivity === null || (time() - $this->lastActivity) > self::TOUCH_INTERVAL;
        if ($this->dirty || $this->newCookie || $stale) {
            $userId = $this->data['uid'] ?? null;
            $this->store->write(
                Crypto::hashToken($this->id),
                $this->data,
                is_numeric($userId) ? (int) $userId : null,
                $request->ip(),
                $request->userAgent(),
            );
        }
        if ($this->newCookie) {
            $response->withCookie(self::COOKIE, $this->id, [
                'expires' => 0,
                'path' => '/',
                'secure' => $this->secureCookies($request),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        $this->dirty = false;
        $this->newCookie = false;
        $this->destroyId = null;
    }

    /** Завершить все сессии пользователя, кроме текущей (смена пароля, блокировка). */
    public function destroyUserSessions(int $userId, bool $exceptCurrent = true): void
    {
        $this->store->deleteForUser($userId, $exceptCurrent && $this->id !== '' ? Crypto::hashToken($this->id) : null);
    }

    private function secureCookies(Request $request): bool
    {
        return $request->isSecure() || str_starts_with($this->config->appUrl(), 'https://');
    }

    private function maxIdleSeconds(): int
    {
        return $this->config->int('SESSION_LIFETIME_MINUTES', 480) * 60;
    }
}
