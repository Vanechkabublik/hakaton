<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Paywall\AccessDecision;
use App\Paywall\PaywallService;
use App\Paywall\Visitor;
use App\Settings\SettingsService;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

final class PaywallTest extends IntegrationTestCase
{
    private const BODY = '<p>АБЗАЦ-ПЕРВЫЙ</p><p>АБЗАЦ-ВТОРОЙ</p><p>АБЗАЦ-ТРЕТИЙ</p><p>АБЗАЦ-ЧЕТВЁРТЫЙ</p><p>АБЗАЦ-ПЯТЫЙ</p>';

    private function paywall(): PaywallService
    {
        return $this->c->get(PaywallService::class);
    }

    private function visitor(string $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ?int $userId = null): Visitor
    {
        return new Visitor($id, true, $userId);
    }

    private function setLimit(int $limit): void
    {
        $this->c->get(SettingsService::class)->set('paywall.free_articles', (string) $limit);
    }

    public function testFreeArticleIsAlwaysOpen(): void
    {
        $article = $this->fx->article(['access' => 'free']);

        $decision = $this->paywall()->evaluate($article, null, $this->visitor(), false);

        $this->assertTrue($decision->allowed);
        $this->assertSame(AccessDecision::FREE, $decision->reason);
    }

    public function testPremiumIsLockedForGuestsAndDigitalSubscribers(): void
    {
        $article = $this->fx->article(['access' => 'premium']);
        $digital = $this->fx->user();
        $this->fx->subscribe($digital, $this->fx->plan(['tier' => 'digital']));

        $guest = $this->paywall()->evaluate($article, null, $this->visitor(), false);
        $sub = $this->paywall()->evaluate($article, $this->c->get(\App\User\UserRepository::class)->find($digital->id), $this->visitor(userId: $digital->id), false);

        $this->assertTrue($guest->isLocked());
        $this->assertTrue($guest->needsPremium);
        $this->assertTrue($sub->isLocked(), 'Digital не открывает Premium-материалы');
        $this->assertTrue($sub->needsPremium);
    }

    public function testPremiumSubscriberReadsEverything(): void
    {
        $user = $this->fx->user();
        $this->fx->subscribe($user, $this->fx->plan(['tier' => 'premium']));

        foreach (['digital', 'premium'] as $level) {
            $decision = $this->paywall()->evaluate($this->fx->article(['access' => $level]), $user, $this->visitor(userId: $user->id), false);
            $this->assertTrue($decision->allowed, "Premium-подписчик должен читать {$level}");
            $this->assertSame(AccessDecision::SUBSCRIBER, $decision->reason);
        }
    }

    public function testExpiredSubscriptionGivesNoAccess(): void
    {
        $user = $this->fx->user();
        $sub = $this->fx->subscribe($user, $this->fx->plan(['tier' => 'premium']));
        $this->db->update('subscriptions', ['current_period_end' => new \DateTimeImmutable('-1 minute')], ['id' => $sub->id]);

        $decision = $this->paywall()->evaluate($this->fx->article(['access' => 'premium']), $user, $this->visitor(userId: $user->id), false);

        $this->assertTrue($decision->isLocked());
    }

    public function testDigitalMeterAllowsConfiguredFreeArticlesPerMonth(): void
    {
        $this->setLimit(3);
        $visitor = $this->visitor();
        $articles = [];
        for ($i = 0; $i < 5; ++$i) {
            $articles[] = $this->fx->article(['access' => 'digital']);
        }

        $remaining = [];
        for ($i = 0; $i < 3; ++$i) {
            $decision = $this->paywall()->evaluate($articles[$i], null, $visitor, false);
            $this->assertTrue($decision->allowed, 'Статья ' . ($i + 1) . ' из 3 бесплатных');
            $this->assertSame(AccessDecision::METERED, $decision->reason);
            $remaining[] = $decision->remaining;
        }
        $this->assertSame([2, 1, 0], $remaining);

        $this->assertTrue($this->paywall()->evaluate($articles[3], null, $visitor, false)->isLocked(), 'Четвёртая статья закрыта');
        $this->assertTrue($this->paywall()->evaluate($articles[4], null, $visitor, false)->isLocked());
    }

    public function testRereadingSameArticleDoesNotConsumeMeter(): void
    {
        $this->setLimit(2);
        $visitor = $this->visitor();
        $first = $this->fx->article(['access' => 'digital']);
        $second = $this->fx->article(['access' => 'digital']);

        for ($i = 0; $i < 4; ++$i) {
            $this->assertTrue($this->paywall()->evaluate($first, null, $visitor, false)->allowed);
        }

        $this->assertTrue($this->paywall()->evaluate($second, null, $visitor, false)->allowed, 'Повторные чтения не тратили лимит');
    }

    public function testMeterIsPerVisitor(): void
    {
        $this->setLimit(1);
        $article = $this->fx->article(['access' => 'digital']);
        $other = $this->fx->article(['access' => 'digital']);

        $this->paywall()->evaluate($article, null, $this->visitor('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'), false);

        $this->assertTrue($this->paywall()->evaluate($other, null, $this->visitor('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1'), false)->isLocked());
        $this->assertTrue($this->paywall()->evaluate($other, null, $this->visitor('b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2'), false)->allowed);
    }

    public function testZeroLimitClosesAllDigitalMaterials(): void
    {
        $this->setLimit(0);

        $decision = $this->paywall()->evaluate($this->fx->article(['access' => 'digital']), null, $this->visitor(), false);

        $this->assertTrue($decision->isLocked());
    }

    public function testBotsGetPreviewWithoutConsumingMeter(): void
    {
        $this->setLimit(3);
        $article = $this->fx->article(['access' => 'digital']);
        $visitor = $this->visitor();

        $decision = $this->paywall()->evaluate($article, null, $visitor, true);

        $this->assertTrue($decision->isLocked());
        $this->assertSame(3, $this->paywall()->remaining($visitor));
    }

    public function testAuthorAndStaffAlwaysReadTheirMaterials(): void
    {
        $author = $this->fx->user(['role' => 'author']);
        $mine = $this->fx->article(['access' => 'premium', 'author_id' => $author->id]);
        $foreign = $this->fx->article(['access' => 'premium']);
        $admin = $this->fx->admin();

        $this->assertSame(AccessDecision::STAFF, $this->paywall()->evaluate($mine, $author, $this->visitor(userId: $author->id), false)->reason);
        $this->assertTrue($this->paywall()->evaluate($foreign, $author, $this->visitor(userId: $author->id), false)->isLocked(), 'Чужой премиум-материал автору закрыт');
        $this->assertTrue($this->paywall()->evaluate($foreign, $admin, $this->visitor(userId: $admin->id), false)->allowed);
    }

    public function testPreviewContainsOnlyConfiguredNumberOfParagraphs(): void
    {
        $article = $this->fx->article(['access' => 'premium', 'body' => self::BODY]);
        $this->c->get(SettingsService::class)->set('paywall.preview_paragraphs', '2');

        $preview = $this->paywall()->preview($article);

        $this->assertStringContainsString('АБЗАЦ-ВТОРОЙ', $preview);
        $this->assertStringNotContainsString('АБЗАЦ-ТРЕТИЙ', $preview);
    }

    // ---- через HTTP --------------------------------------------------------------------------

    public function testLockedPageDoesNotLeakBodyToGuests(): void
    {
        $article = $this->fx->article(['access' => 'premium', 'slug' => 'closed', 'body' => self::BODY]);

        $response = $this->get('/news/' . $article->slug);

        $this->assertSame(200, $response->status());
        $html = $response->body();
        $this->assertStringContainsString('АБЗАЦ-ПЕРВЫЙ', $html, 'Превью открыто');
        $this->assertStringNotContainsString('АБЗАЦ-ЧЕТВЁРТЫЙ', $html, 'Закрытая часть не должна попадать в HTML');
        $this->assertStringNotContainsString('АБЗАЦ-ПЯТЫЙ', $html);
        $this->assertStringContainsString('"isAccessibleForFree":false', str_replace(' ', '', $html), 'Разметка платного контента для поисковиков');
    }

    public function testSubscriberSeesWholeArticleOverHttp(): void
    {
        $article = $this->fx->article(['access' => 'premium', 'slug' => 'closed', 'body' => self::BODY]);
        $user = $this->fx->user(['email' => 'paid@example.test']);
        $this->fx->subscribe($user, $this->fx->plan(['tier' => 'premium']));
        $this->loginAs('paid@example.test', Fixtures::PASSWORD);

        $html = $this->get('/news/' . $article->slug)->body();

        $this->assertStringContainsString('АБЗАЦ-ПЯТЫЙ', $html);
    }

    public function testMeterWorksThroughVisitorCookie(): void
    {
        $this->setLimit(2);
        $a = $this->fx->article(['access' => 'digital', 'slug' => 'a', 'body' => self::BODY]);
        $b = $this->fx->article(['access' => 'digital', 'slug' => 'b', 'body' => self::BODY]);
        $c = $this->fx->article(['access' => 'digital', 'slug' => 'c', 'body' => self::BODY]);

        $this->assertStringContainsString('АБЗАЦ-ПЯТЫЙ', $this->get('/news/' . $a->slug)->body());
        $this->assertStringContainsString('АБЗАЦ-ПЯТЫЙ', $this->get('/news/' . $b->slug)->body());
        $this->assertStringNotContainsString('АБЗАЦ-ПЯТЫЙ', $this->get('/news/' . $c->slug)->body(), 'Третья статья за месяц закрыта');
        $this->assertStringContainsString('АБЗАЦ-ПЯТЫЙ', $this->get('/news/' . $a->slug)->body(), 'Уже прочитанная остаётся доступной');
        $this->assertArrayHasKey('vid', $this->cookies);

        // «чистый» браузер без cookie получает свой лимит
        $this->cookies = [];
        $this->assertStringContainsString('АБЗАЦ-ПЯТЫЙ', $this->get('/news/' . $c->slug)->body());
    }

    public function testSearchBotGetsPreviewOnly(): void
    {
        $article = $this->fx->article(['access' => 'digital', 'slug' => 'for-bots', 'body' => self::BODY]);

        $html = $this->request('GET', '/news/' . $article->slug, [], ['User-Agent' => 'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'])->body();

        $this->assertStringContainsString('АБЗАЦ-ПЕРВЫЙ', $html);
        $this->assertStringNotContainsString('АБЗАЦ-ПЯТЫЙ', $html);
    }

    public function testDraftsAreNotPublic(): void
    {
        $draft = $this->fx->article(['status' => 'draft', 'slug' => 'secret-draft', 'published_at' => null]);

        $this->assertSame(404, $this->get('/news/' . $draft->slug)->status());
    }
}
