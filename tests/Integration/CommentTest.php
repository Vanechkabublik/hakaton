<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Article\Article;
use App\Comment\CommentRepository;
use App\Comment\CommentService;
use App\Comment\CommentTree;
use App\Settings\SettingsService;
use App\User\User;
use DateTimeImmutable;
use Tests\Support\Fixtures;
use Tests\Support\IntegrationTestCase;

final class CommentTest extends IntegrationTestCase
{
    private Article $article;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->article = $this->fx->article(['slug' => 'discuss']);
        $this->alice = $this->fx->user(['email' => 'alice@example.test', 'name' => 'Алиса']);
        $this->bob = $this->fx->user(['email' => 'bob@example.test', 'name' => 'Боб']);
    }

    private function service(): CommentService
    {
        return $this->c->get(CommentService::class);
    }

    private function comments(): CommentRepository
    {
        return $this->c->get(CommentRepository::class);
    }

    public function testPostedCommentIsApprovedAndCounted(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Отличная статья!', null, '127.0.0.1');

        $this->assertSame('approved', $comment->status);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT comments_count FROM articles WHERE id = :id', ['id' => $this->article->id]));
    }

    public function testReplyNotifiesParentAuthorOnly(): void
    {
        $parent = $this->service()->post($this->alice, $this->article, 'Вопрос по теме', null, null);
        $this->runQueue();
        $before = count($this->mail->to('alice@example.test'));

        $this->service()->post($this->bob, $this->article, 'Вот ответ', $parent->id, null);
        $this->runQueue();
        $this->assertCount($before + 1, $this->mail->to('alice@example.test'));

        // ответ самому себе уведомления не создаёт
        $this->service()->post($this->alice, $this->article, 'Дополню себя', $parent->id, null);
        $this->runQueue();
        $this->assertCount($before + 1, $this->mail->to('alice@example.test'));
    }

    public function testReplyDepthIsCapped(): void
    {
        $parentId = null;
        for ($i = 0; $i < CommentTree::MAX_DEPTH + 3; ++$i) {
            $comment = $this->service()->post($i % 2 === 0 ? $this->alice : $this->bob, $this->article, "Уровень {$i}", $parentId, null);
            $parentId = $comment->id;
        }

        $depth = 0;
        $cursor = $this->comments()->find($parentId);
        while ($cursor !== null && $cursor->parentId !== null) {
            ++$depth;
            $cursor = $this->comments()->find($cursor->parentId);
        }
        $this->assertLessThan(CommentTree::MAX_DEPTH, $depth, 'Дерево не глубже максимума');
    }

    public function testLinksSendFirstCommentsToModeration(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Смотрите https://example.org/spam', null, null);

        $this->assertSame('pending', $comment->status);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT comments_count FROM articles WHERE id = :id', ['id' => $this->article->id]), 'Непроверенные не считаются');
    }

    public function testPremoderationSettingHoldsEverything(): void
    {
        $this->c->get(SettingsService::class)->set('comments.premoderation', '1');

        $this->assertSame('pending', $this->service()->post($this->alice, $this->article, 'Обычный комментарий', null, null)->status);
    }

    public function testModeratorApprovalPublishesAndNotifies(): void
    {
        $parent = $this->service()->post($this->alice, $this->article, 'Родитель', null, null);
        $reply = $this->service()->post($this->bob, $this->article, 'Ссылка https://example.org/x', $parent->id, null);
        $this->assertSame('pending', $reply->status);

        $this->service()->moderate($reply, 'approved', $this->fx->admin());

        $this->assertSame('approved', $this->comments()->find($reply->id)->status);
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT comments_count FROM articles WHERE id = :id', ['id' => $this->article->id]));
    }

    public function testDuplicateCommentIsRejected(): void
    {
        $this->service()->post($this->alice, $this->article, 'Один и тот же текст', null, null);

        $this->expectException(\App\Core\Http\ValidationException::class);
        $this->service()->post($this->alice, $this->article, 'Один и тот же текст', null, null);
    }

    public function testUnverifiedAndBlockedUsersCannotComment(): void
    {
        $unverified = $this->fx->user(['email_verified' => false]);
        $blocked = $this->fx->user();
        $this->db->update('users', ['status' => 'blocked'], ['id' => $blocked->id]);
        $blocked = $this->c->get(\App\User\UserRepository::class)->find($blocked->id);

        foreach ([$unverified, $blocked] as $user) {
            try {
                $this->service()->post($user, $this->article, 'Привет', null, null);
                $this->fail('Комментарий не должен быть принят');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCommentsToClosedOrUnpublishedArticlesAreRejected(): void
    {
        $closed = $this->fx->article(['allow_comments' => false]);
        $draft = $this->fx->article(['status' => 'draft']);

        foreach ([$closed, $draft] as $article) {
            try {
                $this->service()->post($this->alice, $article, 'Привет', null, null);
                $this->fail();
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAuthorCanEditWithinFiveMinutesOnly(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Исходный текст', null, null);

        $edited = $this->service()->edit($this->alice, $comment, 'Исправленный текст');
        $this->assertSame('Исправленный текст', $edited->body);
        $this->assertNotNull($edited->editedAt);

        $this->db->update('comments', ['created_at' => new DateTimeImmutable('-6 minutes')], ['id' => $comment->id]);
        $this->expectException(\DomainException::class);
        $this->service()->edit($this->alice, $this->comments()->find($comment->id), 'Поздняя правка');
    }

    public function testStrangerCannotEditComment(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Мой текст', null, null);

        $this->expectException(\DomainException::class);
        $this->service()->edit($this->bob, $comment, 'Чужая правка');
    }

    public function testEditAddingLinkSendsBackToModeration(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Чистый текст', null, null);

        $edited = $this->service()->edit($this->alice, $comment, 'Теперь со ссылкой https://example.org');

        $this->assertSame('pending', $edited->status);
    }

    public function testLikesToggleAndSelfLikeIsForbidden(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Лайкни меня', null, null);

        $this->assertSame(['liked' => true, 'count' => 1], $this->service()->toggleLike($this->bob, $comment));
        $this->assertSame(['liked' => false, 'count' => 0], $this->service()->toggleLike($this->bob, $comment));

        $this->expectException(\DomainException::class);
        $this->service()->toggleLike($this->alice, $comment);
    }

    public function testReportsAreDedupedAndAutoHideAtThreshold(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Спорный комментарий', null, null);
        $reporters = [$this->bob, $this->fx->user(), $this->fx->user()];

        $this->assertTrue($this->service()->report($reporters[0], $comment, 'spam', ''));
        $this->assertFalse($this->service()->report($reporters[0], $comment, 'spam', ''), 'Повторная жалоба от того же человека не считается');
        $this->service()->report($reporters[1], $comment, 'abuse', '');
        $this->assertSame('approved', $this->comments()->find($comment->id)->status);

        $this->service()->report($reporters[2], $comment, 'abuse', '');
        $this->assertSame('pending', $this->comments()->find($comment->id)->status, 'После 3 жалоб скрывается до решения модератора');
    }

    public function testCannotReportOwnComment(): void
    {
        $comment = $this->service()->post($this->alice, $this->article, 'Мой', null, null);

        $this->expectException(\DomainException::class);
        $this->service()->report($this->alice, $comment, 'spam', '');
    }

    // ---- AJAX через HTTP --------------------------------------------------------------------

    public function testPostingOverAjaxReturnsRenderedFragment(): void
    {
        $this->loginAs('alice@example.test', Fixtures::PASSWORD);
        $token = $this->csrfToken('/news/discuss');

        $response = $this->request('POST', '/api/articles/' . $this->article->id . '/comments', ['body' => 'Привет из AJAX <b>и HTML</b>'], [
            'X-CSRF-Token' => $token,
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'application/json',
        ]);

        $this->assertSame(201, $response->status());
        $json = json_decode($response->body(), true);
        $this->assertTrue($json['ok']);
        $this->assertSame('approved', $json['status']);
        $this->assertStringContainsString('Привет из AJAX', $json['html']);
        $this->assertStringNotContainsString('<b>и HTML</b>', $json['html'], 'HTML в комментариях экранируется');
        $this->assertStringContainsString('Привет из AJAX', $this->get('/news/discuss')->body());
    }

    public function testAnonymousCannotPostComments(): void
    {
        $response = $this->postJson('/api/articles/' . $this->article->id . '/comments', ['body' => 'Аноним']);

        $this->assertContains($response->status(), [401, 419], 'Без входа и без CSRF запрос отклоняется');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT count(*) FROM comments'));
    }

    public function testCommentPostingIsRateLimited(): void
    {
        $this->loginAs('alice@example.test', Fixtures::PASSWORD);
        $token = $this->csrfToken('/news/discuss');
        $statuses = [];
        for ($i = 0; $i < 8; ++$i) {
            $statuses[] = $this->request('POST', '/api/articles/' . $this->article->id . '/comments', ['body' => "Комментарий номер {$i}"], [
                'X-CSRF-Token' => $token, 'Accept' => 'application/json',
            ])->status();
        }

        $this->assertSame(201, $statuses[0]);
        $this->assertContains(429, $statuses, 'После 6 комментариев в минуту включается ограничение');
    }
}
