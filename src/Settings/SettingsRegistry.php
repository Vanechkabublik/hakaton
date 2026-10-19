<?php

declare(strict_types=1);

namespace App\Settings;

/** Каталог всех настроек сайта, редактируемых в админке. */
final class SettingsRegistry
{
    /** @var array<string,string> */
    public const GROUPS = [
        'general' => 'Сайт',
        'seo' => 'SEO',
        'paywall' => 'Пейволл',
        'comments' => 'Комментарии',
        'billing' => 'Подписки и платежи',
        'yookassa' => 'ЮKassa',
        'yandex' => 'Яндекс ID (OAuth)',
        'mail' => 'Почта (SMTP)',
        'push' => 'Push-уведомления',
        'analytics' => 'Аналитика',
    ];

    /** @var array<string,SettingDefinition>|null */
    private static ?array $definitions = null;

    /** @return array<string,SettingDefinition> */
    public static function all(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }
        $list = [
            // Сайт
            new SettingDefinition('site.name', 'general', 'Название сайта', 'text', 'Новости Vancodex', 'SITE_NAME'),
            new SettingDefinition('site.tagline', 'general', 'Слоган', 'text', 'Независимое издание о технологиях, бизнесе и обществе'),
            new SettingDefinition('site.description', 'general', 'Описание сайта (для поисковиков)', 'textarea', 'Новости, аналитика и лонгриды. Подписка открывает все материалы.'),
            new SettingDefinition('site.logo', 'general', 'Логотип', 'image', '', null, 'PNG/SVG/WebP, отображается в шапке и письмах'),
            new SettingDefinition('site.contact_email', 'general', 'Контактный email', 'text', '', 'CONTACT_EMAIL'),
            // SEO
            new SettingDefinition('seo.title_template', 'seo', 'Шаблон <title> страниц', 'text', '{title} — {site}', null, 'Подстановки: {title}, {site}'),
            new SettingDefinition('seo.article_title_template', 'seo', 'Шаблон <title> статей', 'text', '{title} | {site}', null, 'Подстановки: {title}, {site}, {category}'),
            new SettingDefinition('seo.home_title', 'seo', 'Заголовок главной', 'text', '{site} — {tagline}', null, 'Подстановки: {site}, {tagline}'),
            new SettingDefinition('seo.description_template', 'seo', 'Шаблон description', 'text', '{lead}', null, 'Используется, если у статьи не задан свой description. Подстановки: {lead}, {title}, {site}'),
            new SettingDefinition('seo.og_image', 'seo', 'Картинка для соцсетей по умолчанию', 'image', ''),
            new SettingDefinition('seo.twitter', 'seo', 'Twitter/X аккаунт', 'text', '', null, 'например @vancodex'),
            // Пейволл
            new SettingDefinition('paywall.free_articles', 'paywall', 'Бесплатных статей в месяц', 'int', '3', null, '0 — премиум-материалы сразу закрыты'),
            new SettingDefinition('paywall.preview_paragraphs', 'paywall', 'Абзацев в превью закрытой статьи', 'int', '3'),
            new SettingDefinition('paywall.title', 'paywall', 'Заголовок блока подписки', 'text', 'Этот материал — для подписчиков'),
            new SettingDefinition('paywall.text', 'paywall', 'Текст блока подписки', 'textarea', 'Оформите подписку и читайте все материалы без ограничений. Отменить можно в любой момент.'),
            new SettingDefinition('paywall.cta', 'paywall', 'Текст кнопки', 'text', 'Оформить подписку'),
            new SettingDefinition('paywall.counter_text', 'paywall', 'Текст счётчика', 'text', 'Бесплатных статей в этом месяце: осталось {left} из {limit}', null, 'Подстановки: {left}, {limit}'),
            // Комментарии
            new SettingDefinition('comments.premoderation', 'comments', 'Премодерация всех комментариев', 'bool', '0', null, 'Иначе премодерация только для комментариев со ссылками'),
            new SettingDefinition('comments.max_length', 'comments', 'Максимальная длина комментария', 'int', '3000'),
            // Подписки и платежи
            new SettingDefinition('billing.grace_days', 'billing', 'Льготный период после неудачного списания (дней)', 'int', '3'),
            new SettingDefinition('billing.max_renew_attempts', 'billing', 'Попыток автопродления', 'int', '3'),
            new SettingDefinition('billing.recurring_enabled', 'billing', 'Автопродление (сохранение карты)', 'bool', '1', null, 'Требует подключённых «Автоплатежей» в личном кабинете ЮKassa'),
            new SettingDefinition('billing.gift_valid_days', 'billing', 'Срок действия подарочного кода (дней)', 'int', '365'),
            // ЮKassa
            new SettingDefinition('yookassa.shop_id', 'yookassa', 'shopId', 'text', '', 'YOOKASSA_SHOP_ID'),
            new SettingDefinition('yookassa.secret_key', 'yookassa', 'Секретный ключ', 'secret', '', 'YOOKASSA_SECRET_KEY'),
            new SettingDefinition('yookassa.confirmation', 'yookassa', 'Способ оплаты', 'select', 'redirect', null, 'Редирект на страницу ЮKassa или виджет на нашем сайте', [
                'redirect' => 'Редирект на страницу ЮKassa',
                'embedded' => 'Виджет на странице сайта',
            ]),
            new SettingDefinition('yookassa.send_receipt', 'yookassa', 'Передавать данные чека (54-ФЗ)', 'bool', '1', null, 'Нужно, если подключены «Чеки от ЮKassa»'),
            new SettingDefinition('yookassa.vat_code', 'yookassa', 'Ставка НДС в чеке', 'select', '1', null, '', [
                '1' => 'Без НДС', '2' => '0%', '3' => '10%', '4' => '20%', '5' => '10/110', '6' => '20/120',
            ]),
            new SettingDefinition('yookassa.tax_system_code', 'yookassa', 'Система налогообложения', 'select', '', null, 'Если в магазине одна система — оставьте пустым', [
                '' => 'Не передавать', '1' => 'ОСН', '2' => 'УСН (доходы)', '3' => 'УСН (доходы − расходы)', '4' => 'ЕНВД', '5' => 'ЕСН', '6' => 'Патент',
            ]),
            // Яндекс ID
            new SettingDefinition('yandex.client_id', 'yandex', 'Client ID', 'text', '', 'YANDEX_CLIENT_ID', 'Redirect URI: {APP_URL}/auth/yandex/callback'),
            new SettingDefinition('yandex.client_secret', 'yandex', 'Client secret', 'secret', '', 'YANDEX_CLIENT_SECRET'),
            // Почта
            new SettingDefinition('mail.host', 'mail', 'SMTP-сервер', 'text', '', 'MAIL_HOST'),
            new SettingDefinition('mail.port', 'mail', 'Порт', 'int', '587', 'MAIL_PORT'),
            new SettingDefinition('mail.encryption', 'mail', 'Шифрование', 'select', 'tls', 'MAIL_ENCRYPTION', '', ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Без шифрования']),
            new SettingDefinition('mail.username', 'mail', 'Логин', 'text', '', 'MAIL_USERNAME'),
            new SettingDefinition('mail.password', 'mail', 'Пароль', 'secret', '', 'MAIL_PASSWORD'),
            new SettingDefinition('mail.from_address', 'mail', 'Адрес отправителя', 'text', '', 'MAIL_FROM_ADDRESS'),
            new SettingDefinition('mail.from_name', 'mail', 'Имя отправителя', 'text', '', 'MAIL_FROM_NAME'),
            // Push
            new SettingDefinition('push.vapid_public', 'push', 'VAPID public key', 'text', '', 'VAPID_PUBLIC_KEY', 'Сгенерировать: php bin/console vapid:generate'),
            new SettingDefinition('push.vapid_private', 'push', 'VAPID private key', 'secret', '', 'VAPID_PRIVATE_KEY'),
            new SettingDefinition('push.subject', 'push', 'VAPID subject', 'text', '', 'VAPID_SUBJECT', 'mailto:you@example.com или URL сайта'),
            // Аналитика
            new SettingDefinition('analytics.metrika_id', 'analytics', 'Яндекс.Метрика — номер счётчика', 'text', '', 'YANDEX_METRIKA_ID'),
            new SettingDefinition('analytics.ga4_id', 'analytics', 'Google Analytics 4 — Measurement ID', 'text', '', 'GA4_ID', 'формата G-XXXXXXXXXX'),
        ];
        self::$definitions = [];
        foreach ($list as $definition) {
            self::$definitions[$definition->key] = $definition;
        }

        return self::$definitions;
    }

    public static function get(string $key): ?SettingDefinition
    {
        return self::all()[$key] ?? null;
    }

    /** @return array<string,list<SettingDefinition>> */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::all() as $definition) {
            $grouped[$definition->group][] = $definition;
        }

        return $grouped;
    }
}
