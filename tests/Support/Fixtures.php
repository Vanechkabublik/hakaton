<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Article\Article;
use App\Article\ArticleRepository;
use App\Billing\Plan;
use App\Billing\PlanRepository;
use App\Billing\Subscription;
use App\Billing\SubscriptionService;
use App\Core\Container;
use App\Core\Database\Database;
use App\Core\Security\PasswordHasher;
use App\User\Role;
use App\User\User;
use App\User\UserRepository;
use DateTimeImmutable;

/** Фабрики тестовых данных поверх настоящих репозиториев. */
final class Fixtures
{
    public const PASSWORD = 'Passw0rd123';

    private int $counter = 0;

    /** @var array<string,string> Argon2id-хеш дорогой, поэтому одинаковые пароли хешируем один раз за запуск */
    private static array $hashes = [];

    public function __construct(private readonly Container $c)
    {
    }

    /** @param array<string,mixed> $overrides email, name, role, email_verified, password */
    public function user(array $overrides = []): User
    {
        $n = ++$this->counter;
        $password = (string) ($overrides['password'] ?? self::PASSWORD);
        unset($overrides['password']);
        $repository = $this->c->get(UserRepository::class);
        $user = $repository->createUser(array_merge([
            'email' => "user{$n}@example.test",
            'name' => "Пользователь {$n}",
            'password_hash' => self::$hashes[$password] ??= $this->c->get(PasswordHasher::class)->hash($password),
            'role' => Role::Reader->value,
            'email_verified' => true,
            'digest_frequency' => 'none',
        ], $overrides));
        $repository->ensureSlug($user);

        return $repository->find($user->id) ?? $user;
    }

    public function admin(): User
    {
        return $this->user(['role' => Role::Admin->value, 'name' => 'Админ']);
    }

    /** @param array<string,mixed> $overrides */
    public function plan(array $overrides = []): Plan
    {
        $n = ++$this->counter;
        $repository = $this->c->get(PlanRepository::class);
        $id = $repository->save(null, array_merge([
            'code' => "plan-{$n}",
            'name' => 'Digital',
            'tier' => 'digital',
            'period' => 'month',
            'price' => 30000,
            'trial_days' => 0,
            'is_active' => true,
            'sort_order' => $n,
        ], $overrides));

        return $repository->find($id) ?? throw new \RuntimeException('План не создан');
    }

    /** @param array<string,mixed> $overrides колонки таблицы articles */
    public function article(array $overrides = []): Article
    {
        $n = ++$this->counter;
        $db = $this->c->get(Database::class);
        $id = (int) $db->insert('articles', array_merge([
            'type' => 'news',
            'status' => 'published',
            'access' => 'free',
            'slug' => "article-{$n}",
            'title' => "Статья {$n}",
            'lead' => 'Короткое вступление.',
            'body' => '<p>Первый абзац.</p><p>Второй абзац.</p><p>Третий абзац.</p><p>Четвёртый абзац.</p>',
            'published_at' => new DateTimeImmutable('-1 hour'),
        ], $overrides));

        return $this->c->get(ArticleRepository::class)->find($id) ?? throw new \RuntimeException('Статья не создана');
    }

    public function subscribe(User $user, Plan $plan, string $until = '+30 days'): Subscription
    {
        return $this->c->get(SubscriptionService::class)->grantManual($user, $plan, new DateTimeImmutable($until), $this->admin());
    }
}
