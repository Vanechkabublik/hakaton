<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\Totp;
use App\Core\Security\Crypto;
use App\User\Role;
use App\User\UserRepository;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

final class AuthFlowTest extends IntegrationTestCase
{
    private function register(string $email = 'ivan@example.test'): void
    {
        $response = $this->post('/register', [
            'name' => 'Иван Петров',
            'email' => $email,
            'password' => Fixtures::PASSWORD,
            'password_confirmation' => Fixtures::PASSWORD,
            'terms' => '1',
        ], '/register');
        $this->assertRedirectTo('/verify-email/pending', $response);
    }

    /** Включает TOTP сотруднику напрямую в БД и возвращает секрет. */
    private function enableTotp(int $userId): string
    {
        $secret = (new Totp())->generateSecret();
        $this->db->update('users', [
            'totp_secret' => $this->c->get(Crypto::class)->encrypt($secret),
            'totp_enabled_at' => new \DateTimeImmutable(),
            'totp_last_step' => 0,
        ], ['id' => $userId]);

        return $secret;
    }

    public function testRegistrationVerificationAndLogin(): void
    {
        $this->register();

        $user = $this->c->get(UserRepository::class)->findByEmail('ivan@example.test');
        $this->assertNotNull($user);
        $this->assertFalse($user->isEmailVerified());
        $this->assertNotSame(Fixtures::PASSWORD, $user->passwordHash, 'Пароль хранится только в виде хеша');

        $mail = $this->mail->last();
        $this->assertNotNull($mail, 'Должно уйти письмо с подтверждением');
        $this->assertSame('ivan@example.test', $mail->to);
        $link = $this->mail->linkIn($mail, '/verify-email/');
        $this->assertNotNull($link);

        $response = $this->get((string) parse_url($link, PHP_URL_PATH));
        $this->assertRedirectTo('/login', $response);
        $this->assertTrue($this->c->get(UserRepository::class)->findByEmail('ivan@example.test')?->isEmailVerified());

        $this->assertRedirectTo('/account', $this->loginAs('ivan@example.test', Fixtures::PASSWORD));
        $this->assertSame(200, $this->get('/account')->status());
    }

    public function testVerificationLinkIsSingleUse(): void
    {
        $this->register();
        $mail = $this->mail->last();
        $this->assertNotNull($mail);
        $path = (string) parse_url((string) $this->mail->linkIn($mail, '/verify-email/'), PHP_URL_PATH);

        $this->get($path);
        $second = $this->get($path);

        $this->assertRedirectTo('/verify-email/pending', $second);
    }

    public function testLoginBeforeVerificationIsRefused(): void
    {
        $this->register();

        $response = $this->loginAs('ivan@example.test', Fixtures::PASSWORD);

        $this->assertRedirectTo('/verify-email/pending', $response);
        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testDuplicateEmailCannotRegister(): void
    {
        $this->fx->user(['email' => 'taken@example.test']);

        $response = $this->post('/register', [
            'name' => 'Другой',
            'email' => 'TAKEN@example.test',
            'password' => Fixtures::PASSWORD,
            'password_confirmation' => Fixtures::PASSWORD,
            'terms' => '1',
        ], '/register');

        $this->assertFalse($response->isRedirect() && str_contains((string) $response->header('Location'), 'pending'));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT count(*) FROM users WHERE lower(email) = 'taken@example.test'"));
    }

    public function testWrongPasswordAndUnknownEmailGiveSameError(): void
    {
        $this->fx->user(['email' => 'known@example.test']);

        $wrong = $this->loginAs('known@example.test', 'WrongPass999');
        $unknown = $this->loginAs('nobody@example.test', 'WrongPass999');

        $this->assertSame($wrong->status(), $unknown->status());
        $this->assertSame($wrong->header('Location'), $unknown->header('Location'));
        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testAccountIsLockedAfterRepeatedFailures(): void
    {
        $this->fx->user(['email' => 'victim@example.test']);

        for ($i = 0; $i < 5; ++$i) {
            $this->loginAs('victim@example.test', 'WrongPass' . $i);
        }
        // даже верный пароль не пускает, пока действует блокировка
        $this->loginAs('victim@example.test', Fixtures::PASSWORD);

        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $this->fx->user(['email' => 'csrf@example.test']);

        $response = $this->request('POST', '/login', ['email' => 'csrf@example.test', 'password' => Fixtures::PASSWORD]);

        $this->assertSame(419, $response->status());
        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testPostWithForgedCsrfTokenIsRejected(): void
    {
        $this->fx->user(['email' => 'csrf2@example.test']);
        $this->csrfToken('/login');

        $response = $this->request('POST', '/login', ['email' => 'csrf2@example.test', 'password' => Fixtures::PASSWORD, '_token' => 'forged-token']);

        $this->assertSame(419, $response->status());
    }

    public function testLogoutEndsSession(): void
    {
        $this->fx->user(['email' => 'out@example.test']);
        $this->loginAs('out@example.test', Fixtures::PASSWORD);
        $this->assertSame(200, $this->get('/account')->status());

        $this->post('/logout');

        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testSessionIdChangesOnLogin(): void
    {
        $this->fx->user(['email' => 'fixation@example.test']);
        $this->csrfToken('/login');
        $before = $this->cookies['nsid'] ?? null;
        $this->assertNotNull($before);

        $this->loginAs('fixation@example.test', Fixtures::PASSWORD);

        $this->assertNotSame($before, $this->cookies['nsid'] ?? null, 'Идентификатор сессии обязан смениться при входе (защита от фиксации)');
    }

    public function testRememberMeRestoresSessionAfterSessionCookieIsLost(): void
    {
        $this->fx->user(['email' => 'remember@example.test']);
        $this->post('/login', ['email' => 'remember@example.test', 'password' => Fixtures::PASSWORD, 'remember' => '1'], '/login');
        $this->assertArrayHasKey('remember', $this->cookies);

        unset($this->cookies['nsid']);

        $this->assertSame(200, $this->get('/account')->status());
    }

    public function testGuestsAndReadersCannotOpenAdmin(): void
    {
        $this->assertContains($this->get('/admin')->status(), [302, 401, 403]);

        $this->fx->user(['email' => 'reader@example.test']);
        $this->loginAs('reader@example.test', Fixtures::PASSWORD);
        $this->assertSame(403, $this->get('/admin')->status());
    }

    public function testStaffWithoutTotpMustEnrollBeforeUsingAdmin(): void
    {
        $this->fx->user(['email' => 'boss@example.test', 'role' => Role::Admin->value]);
        $this->loginAs('boss@example.test', Fixtures::PASSWORD);

        $this->assertRedirectTo('/account/two-factor', $this->get('/admin'));
    }

    public function testBlockedUserCannotLogin(): void
    {
        $user = $this->fx->user(['email' => 'blocked@example.test']);
        $this->db->update('users', ['status' => 'blocked'], ['id' => $user->id]);

        $this->loginAs('blocked@example.test', Fixtures::PASSWORD);

        $this->assertRedirectTo('/login', $this->get('/account'));
    }

    public function testPasswordResetFlow(): void
    {
        $this->fx->user(['email' => 'forgot@example.test']);
        $this->post('/forgot-password', ['email' => 'forgot@example.test'], '/forgot-password');

        $mail = $this->mail->last();
        $this->assertNotNull($mail);
        $link = $this->mail->linkIn($mail, '/reset-password/');
        $this->assertNotNull($link);
        $path = (string) parse_url($link, PHP_URL_PATH);

        $this->assertSame(200, $this->get($path)->status());
        $new = 'N3wPassword2026';
        $this->post($path, ['password' => $new, 'password_confirmation' => $new], $path);

        $this->loginAs('forgot@example.test', Fixtures::PASSWORD);
        $this->assertRedirectTo('/login', $this->get('/account'), 'Старый пароль больше не работает');

        $this->assertRedirectTo('/account', $this->loginAs('forgot@example.test', $new));

        // ссылка одноразовая
        $this->assertNotSame(200, $this->get($path)->status());
    }

    public function testPasswordResetForUnknownEmailDoesNotLeakExistence(): void
    {
        $known = $this->fx->user(['email' => 'real@example.test']);
        $a = $this->post('/forgot-password', ['email' => $known->email], '/forgot-password');
        $b = $this->post('/forgot-password', ['email' => 'ghost@example.test'], '/forgot-password');

        $this->assertSame($a->status(), $b->status());
        $this->assertSame($a->header('Location'), $b->header('Location'));
        $this->assertCount(0, $this->mail->to('ghost@example.test'));
    }

    public function testTwoFactorIsRequiredForStaffWithTotp(): void
    {
        $admin = $this->fx->user(['email' => 'staff@example.test', 'role' => Role::Admin->value]);
        $secret = $this->enableTotp($admin->id);

        $this->assertRedirectTo('/login/two-factor', $this->loginAs('staff@example.test', Fixtures::PASSWORD));
        $this->assertRedirectTo('/login', $this->get('/admin'), 'До ввода кода админка закрыта');

        $this->post('/login/two-factor', ['code' => '000000'], '/login/two-factor');
        $this->assertRedirectTo('/login', $this->get('/admin'), 'Неверный код не открывает доступ');

        $this->assertRedirectTo('/admin', $this->post('/login/two-factor', ['code' => (new Totp())->code($secret)], '/login/two-factor'));
        $this->assertSame(200, $this->get('/admin')->status());
    }

    public function testTotpCodeCannotBeReplayed(): void
    {
        $admin = $this->fx->user(['email' => 'replay@example.test', 'role' => Role::Admin->value]);
        $secret = $this->enableTotp($admin->id);
        $code = (new Totp())->code($secret);

        $this->loginAs('replay@example.test', Fixtures::PASSWORD);
        $this->post('/login/two-factor', ['code' => $code], '/login/two-factor');
        $this->assertSame(200, $this->get('/admin')->status());
        $this->post('/logout');

        // тот же код во второй раз (например, подсмотренный) не принимается
        $this->loginAs('replay@example.test', Fixtures::PASSWORD);
        $this->post('/login/two-factor', ['code' => $code], '/login/two-factor');

        $this->assertRedirectTo('/login', $this->get('/admin'));
    }

    public function testRecoveryCodeWorksOnceInsteadOfTotp(): void
    {
        $admin = $this->fx->user(['email' => 'recovery@example.test', 'role' => Role::Admin->value]);
        $this->enableTotp($admin->id);
        $this->db->update('users', ['totp_recovery' => [Crypto::hashToken('abcde-fg234')]], ['id' => $admin->id]);

        $this->loginAs('recovery@example.test', Fixtures::PASSWORD);
        $this->post('/login/two-factor', ['code' => 'abcde-fg234'], '/login/two-factor');
        $this->assertSame(200, $this->get('/admin')->status());
        $this->post('/logout');

        $this->loginAs('recovery@example.test', Fixtures::PASSWORD);
        $this->post('/login/two-factor', ['code' => 'abcde-fg234'], '/login/two-factor');
        $this->assertRedirectTo('/login', $this->get('/admin'), 'Код восстановления одноразовый');
    }
}
