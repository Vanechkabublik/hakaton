<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\Gateway\PaymentGateway;
use App\Core\Application;
use App\Core\Container;
use App\Core\Database\Database;
use App\Core\Database\Migrator;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Mail\Mailer;
use App\Core\Queue\Worker;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест на настоящей PostgreSQL (база news_test): схема создаётся из миграций один раз за запуск,
 * перед каждым тестом таблицы очищаются. HTTP-запросы идут через Application::handle() без веб-сервера.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static bool $schemaReady = false;

    protected Application $app;
    protected Container $c;
    protected Database $db;
    protected ArrayTransport $mail;
    protected FakePaymentGateway $gateway;
    protected Fixtures $fx;

    /** @var array<string,string> cookie jar «браузера» */
    protected array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new ArrayTransport();
        $this->gateway = new FakePaymentGateway();
        $this->app = $this->newApp();
        $this->c = $this->app->container();
        $this->db = $this->c->get(Database::class);
        $this->cookies = [];

        $name = $this->app->config()->string('DB_NAME');
        if (!str_ends_with($name, '_test')) {
            $this->fail("Интеграционные тесты стирают данные: имя базы должно оканчиваться на _test (сейчас «{$name}»)");
        }
        try {
            $this->db->pdo();
        } catch (\Throwable $e) {
            if (getenv('CI') !== false) {
                $this->fail('PostgreSQL недоступна: ' . $e->getMessage());
            }
            $this->markTestSkipped('PostgreSQL недоступна (' . $e->getMessage() . ')');
        }

        if (!self::$schemaReady) {
            $this->db->pdo()->exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public');
            $this->c->get(Migrator::class)->migrate();
            self::$schemaReady = true;
        } else {
            $tables = $this->db->fetchColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename <> 'migrations'");
            $this->db->pdo()->exec('TRUNCATE ' . implode(', ', array_map(static fn (string $t): string => '"' . $t . '"', $tables)) . ' RESTART IDENTITY CASCADE');
        }
        $this->fx = new Fixtures($this->c);
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        parent::tearDown();
    }

    /** Новое приложение с подставленными фейками (как новый PHP-процесс на каждый запрос). */
    protected function newApp(): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->container()->instance(PaymentGateway::class, $this->gateway);
        $app->container()->get(Mailer::class)->useTransport($this->mail);

        return $app;
    }

    /** @param array<string,mixed> $data @param array<string,string> $headers */
    protected function request(string $method, string $uri, array $data = [], array $headers = []): Response
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->send(fn (array $cookies): Request => Request::create($method, $uri, $data, $server, $cookies));
    }

    /** JSON-запрос без CSRF (вебхуки, API). @param array<string,mixed> $payload */
    protected function postJson(string $uri, array $payload, array $server = []): Response
    {
        $server += ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        return $this->send(fn (array $cookies): Request => Request::create('POST', $uri, $payload, $server, $cookies));
    }

    /** @param callable(array<string,string>):Request $build */
    private function send(callable $build): Response
    {
        $app = $this->newApp();
        $response = $app->handle($build($this->cookies));
        foreach ($response->cookies() as $cookie) {
            $expires = (int) ($cookie['options']['expires'] ?? 0);
            if ($cookie['value'] === '' || ($expires > 0 && $expires < time())) {
                unset($this->cookies[$cookie['name']]);
            } else {
                $this->cookies[$cookie['name']] = $cookie['value'];
            }
        }
        // Фоновые задачи (письма, уведомления) выполняем сразу, как это сделал бы воркер
        $app->container()->get(Worker::class)->drain(100);

        return $response;
    }

    protected function get(string $uri, array $query = []): Response
    {
        return $this->request('GET', $uri, $query);
    }

    /** POST с CSRF-токеном текущей сессии браузера. */
    protected function post(string $uri, array $data = [], ?string $tokenFrom = null): Response
    {
        $data['_token'] ??= $this->csrfToken($tokenFrom);

        return $this->request('POST', $uri, $data);
    }

    protected function csrfToken(?string $page = null): string
    {
        foreach ($page !== null ? [$page] : ['/login', '/account/profile'] as $candidate) {
            $body = $this->request('GET', $candidate)->body();
            if (preg_match('/name="_token" value="([^"]+)"/', $body, $m) === 1 || preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) === 1) {
                return html_entity_decode($m[1]);
            }
        }
        $this->fail('Не найден CSRF-токен ни на одной странице');
    }

    /** Войти пользователем через форму входа. */
    protected function loginAs(string $email, string $password): Response
    {
        return $this->post('/login', ['email' => $email, 'password' => $password], '/login');
    }

    protected function runQueue(): int
    {
        return $this->c->get(Worker::class)->drain(100);
    }

    protected function assertRedirectTo(string $path, Response $response): void
    {
        $this->assertTrue($response->isRedirect(), 'Ожидался редирект, получен статус ' . $response->status());
        $this->assertSame($path, parse_url((string) $response->header('Location'), PHP_URL_PATH));
    }
}
