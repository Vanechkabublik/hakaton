<?php

declare(strict_types=1);

namespace App\Seed;

use App\Billing\PlanRepository;
use App\Core\Config;
use App\Core\Database\Database;
use App\Core\Security\PasswordHasher;
use App\Taxonomy\CategoryRepository;
use App\User\Role;
use App\User\UserRepository;

/** Обязательные начальные данные: рубрики, тарифы, служебные страницы, первый администратор. Идемпотентен. */
final class DefaultDataSeeder
{
    public function __construct(
        private readonly Database $db,
        private readonly CategoryRepository $categories,
        private readonly PlanRepository $plans,
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly Config $config,
    ) {
    }

    /** @return list<string> сообщения о выполненном */
    public function run(): array
    {
        $log = [];
        $log[] = $this->categories();
        $log[] = $this->plans();
        $log[] = $this->pages();
        $admin = $this->admin();
        if ($admin !== null) {
            $log[] = $admin;
        }

        return $log;
    }

    private function categories(): string
    {
        $created = 0;
        foreach ([
            ['Технологии', '#2563eb', 'IT, интернет, гаджеты и искусственный интеллект'],
            ['Бизнес', '#059669', 'Компании, рынки, стартапы и финансы'],
            ['Общество', '#d97706', 'Люди, города, образование и здоровье'],
            ['Наука', '#7c3aed', 'Исследования и открытия'],
            ['Культура', '#db2777', 'Кино, книги, музыка и искусство'],
        ] as $i => [$name, $color, $description]) {
            if ($this->db->fetchValue('SELECT 1 FROM categories WHERE name = :n', ['n' => $name]) === null) {
                $this->categories->save(null, ['name' => $name, 'color' => $color, 'description' => $description, 'sort_order' => $i * 10]);
                ++$created;
            }
        }

        return "Рубрики: создано {$created}";
    }

    private function plans(): string
    {
        if ($this->plans->count() > 0) {
            return 'Тарифы: уже есть';
        }
        $digital = ['Все материалы сайта без ограничений', 'Без рекламы в письмах', 'Закладки и история чтения'];
        $premium = ['Всё из тарифа Digital', 'Эксклюзивные аналитика и лонгриды Premium', 'Ранний доступ к расследованиям', 'Приглашения на закрытые встречи'];
        foreach ([
            ['digital-month', 'Digital', 'digital', 'month', 29900, 7, 'Полный доступ ко всем материалам', $digital, 10],
            ['digital-year', 'Digital', 'digital', 'year', 249000, 0, 'Выгоднее на 30% при оплате за год', $digital, 20],
            ['premium-month', 'Premium', 'premium', 'month', 59900, 0, 'Всё из Digital плюс эксклюзивные материалы', $premium, 30],
            ['premium-year', 'Premium', 'premium', 'year', 499000, 0, 'Максимальная выгода: два месяца в подарок', $premium, 40],
        ] as [$code, $name, $tier, $period, $price, $trial, $description, $features, $sort]) {
            $this->plans->save(null, [
                'code' => $code, 'name' => $name, 'tier' => $tier, 'period' => $period, 'price' => $price,
                'trial_days' => $trial, 'description' => $description, 'features' => $features,
                'is_active' => true, 'sort_order' => $sort,
            ]);
        }

        return 'Тарифы: созданы 4';
    }

    private function pages(): string
    {
        $site = $this->config->string('SITE_NAME', 'Новости Vancodex');
        $host = (string) parse_url($this->config->appUrl(), PHP_URL_HOST);
        $email = $this->config->string('CONTACT_EMAIL', 'info@' . $host);
        $created = 0;
        foreach (DefaultPages::all($site, $host, $email) as $slug => $page) {
            $exists = $this->db->fetchValue('SELECT 1 FROM pages WHERE slug = :s', ['s' => $slug]);
            if ($exists === null) {
                $this->db->insert('pages', ['slug' => $slug, 'title' => $page['title'], 'body' => $page['body'], 'seo_description' => $page['description']]);
                ++$created;
            }
        }

        return "Страницы: создано {$created}";
    }

    private function admin(): ?string
    {
        $email = $this->config->get('ADMIN_EMAIL');
        if ($email === null || $this->users->findByEmail($email) !== null) {
            return null;
        }
        $password = $this->config->get('ADMIN_PASSWORD') ?? bin2hex(random_bytes(8)) . 'Aa1';
        $user = $this->users->createUser([
            'email' => $email,
            'name' => $this->config->string('ADMIN_NAME', 'Администратор'),
            'password_hash' => $this->hasher->hash($password),
            'role' => Role::Admin->value,
            'email_verified' => true,
            'digest_frequency' => 'none',
        ]);
        $this->users->ensureSlug($user);

        return $this->config->get('ADMIN_PASSWORD') === null
            ? "Администратор {$email} создан, пароль: {$password} (смените после входа)"
            : "Администратор {$email} создан";
    }
}
