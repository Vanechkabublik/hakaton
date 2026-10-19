<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Article\ArticleRepository;
use App\Article\ArticleService;
use App\Article\ArticleStatus;
use App\Article\RevisionRepository;
use App\Core\Queue\Queue;
use App\User\Role;
use DateTimeImmutable;
use Tests\Support\IntegrationTestCase;

final class ContentTest extends IntegrationTestCase
{
    private function service(): ArticleService
    {
        return $this->c->get(ArticleService::class);
    }

    private function articles(): ArticleRepository
    {
        return $this->c->get(ArticleRepository::class);
    }

    /** @return array<string,mixed> */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Рынок облачных сервисов вырос',
            'lead' => 'Коротко о главном.',
            'body' => '<p>Первый абзац статьи.</p><p>Второй абзац статьи.</p>',
            'type' => 'analytics',
            'access' => 'free',
        ], $overrides);
    }

    public function testCreateSanitizesBodyAndBuildsSlug(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);

        $article = $this->service()->create($author, $this->input(['body' => '<p onclick="x()">Текст</p><script>alert(1)</script>']));

        $this->assertSame('rynok-oblachnykh-servisov-vyros', $article->slug);
        $this->assertSame(ArticleStatus::Draft, $article->status);
        $this->assertStringNotContainsString('script', $article->body);
        $this->assertStringNotContainsString('onclick', $article->body);
        $this->assertSame($author->id, $article->authorId);
        $this->assertSame(1, $article->readingMinutes);
    }

    public function testDuplicateTitlesGetUniqueSlugs(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);

        $a = $this->service()->create($author, $this->input());
        $b = $this->service()->create($author, $this->input());

        $this->assertNotSame($a->slug, $b->slug);
        $this->assertSame($a->slug . '-2', $b->slug);
    }

    public function testTagsAreCreatedOnTheFly(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);

        $article = $this->service()->create($author, $this->input(['tags' => 'Облако, облако , Кибербезопасность']));

        $names = $this->db->fetchColumn('SELECT t.name FROM tags t JOIN article_tag at ON at.tag_id = t.id WHERE at.article_id = :id ORDER BY t.name', ['id' => $article->id]);
        $this->assertCount(2, $names, 'Дубликаты без учёта регистра схлопываются');
    }

    public function testEditorialWorkflowFromDraftToPublished(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($author, $this->input());

        $pending = $this->service()->transition($article, $author, ArticleStatus::Pending);
        $this->assertSame(ArticleStatus::Pending, $pending->status);
        $this->assertNotNull($pending->submittedAt);

        try {
            $this->service()->transition($pending, $author, ArticleStatus::Published);
            $this->fail('Автор не должен публиковать сам');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('прав', $e->getMessage());
        }

        $published = $this->service()->transition($pending, $editor, ArticleStatus::Published);
        $this->assertSame(ArticleStatus::Published, $published->status);
        $this->assertNotNull($published->publishedAt);
        $this->assertSame(1, $this->c->get(Queue::class)->pendingCount(), 'При публикации ставится задача уведомлений');
    }

    public function testEditorCanReturnToDraftWithNote(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->transition($this->service()->create($author, $this->input()), $author, ArticleStatus::Pending);

        $returned = $this->service()->transition($article, $editor, ArticleStatus::Draft, null, 'Добавьте источники');

        $this->assertSame(ArticleStatus::Draft, $returned->status);
        $this->assertSame('Добавьте источники', $returned->moderationNote);
    }

    public function testEmptyArticleCannotBeSubmitted(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);
        $article = $this->service()->create($author, $this->input(['body' => '']));

        $this->expectException(\DomainException::class);
        $this->service()->transition($article, $author, ArticleStatus::Pending);
    }

    public function testInvalidTransitionIsRejected(): void
    {
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($editor, $this->input());
        $published = $this->service()->transition($article, $editor, ArticleStatus::Published);

        $this->expectException(\DomainException::class);
        $this->service()->transition($published, $editor, ArticleStatus::Pending);
    }

    public function testScheduledPublicationHappensOnlyWhenDue(): void
    {
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($editor, $this->input());
        $scheduled = $this->service()->transition($article, $editor, ArticleStatus::Scheduled, new DateTimeImmutable('+2 hours'));

        $this->assertSame(ArticleStatus::Scheduled, $scheduled->status);
        $this->assertSame(0, $this->service()->publishDue(), 'Время ещё не пришло');
        $this->assertSame(ArticleStatus::Scheduled, $this->articles()->find($article->id)->status);

        $this->db->update('articles', ['publish_at' => new DateTimeImmutable('-1 minute')], ['id' => $article->id]);

        $this->assertSame(1, $this->service()->publishDue());
        $published = $this->articles()->find($article->id);
        $this->assertSame(ArticleStatus::Published, $published->status);
        $this->assertNotNull($published->publishedAt);
        $this->assertSame(0, $this->service()->publishDue(), 'Повторный запуск ничего не делает');
    }

    public function testScheduledDateMustBeInTheFuture(): void
    {
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($editor, $this->input());

        $this->expectException(\DomainException::class);
        $this->service()->transition($article, $editor, ArticleStatus::Scheduled, new DateTimeImmutable('-1 hour'));
    }

    public function testAuthorCannotEditSomeoneElsesArticle(): void
    {
        $owner = $this->fx->user(['role' => Role::Author->value]);
        $stranger = $this->fx->user(['role' => Role::Author->value]);
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($owner, $this->input());

        try {
            $this->service()->update($article, $stranger, $this->input(['title' => 'Взлом']));
            $this->fail('Чужую статью править нельзя');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $updated = $this->service()->update($article, $editor, $this->input(['title' => 'Редакторская правка']));
        $this->assertSame('Редакторская правка', $updated->title);
    }

    public function testRevisionsAreRecordedAndCanBeRestored(): void
    {
        $editor = $this->fx->user(['role' => Role::Editor->value]);
        $article = $this->service()->create($editor, $this->input(['body' => '<p>Версия один</p>']));
        $article = $this->service()->update($article, $editor, $this->input(['body' => '<p>Версия два</p>']));

        $revisions = $this->c->get(RevisionRepository::class)->list($article->id);
        $this->assertCount(2, $revisions);

        $oldest = end($revisions);
        $restored = $this->service()->restoreRevision($article, (int) $oldest['id'], $editor);

        $this->assertStringContainsString('Версия один', $restored->body);
        $this->assertCount(3, $this->c->get(RevisionRepository::class)->list($article->id), 'Восстановление само создаёт новую ревизию');
    }

    public function testOnlyPrivilegedUsersCanDelete(): void
    {
        $author = $this->fx->user(['role' => Role::Author->value]);
        $other = $this->fx->user(['role' => Role::Author->value]);
        $mine = $this->service()->create($author, $this->input());

        try {
            $this->service()->delete($mine, $other);
            $this->fail();
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->delete($mine, $author); // свой черновик удалить можно
        $this->assertNull($this->articles()->find($mine->id));
    }

    // ---- админка через HTTP ------------------------------------------------------------------

    public function testAdminPagesRequireStaffRole(): void
    {
        $reader = $this->fx->user(['email' => 'reader@example.test']);
        $this->loginAs('reader@example.test', \Tests\Support\Fixtures::PASSWORD);

        foreach (['/admin', '/admin/articles', '/admin/users', '/admin/payments', '/admin/settings'] as $path) {
            $this->assertSame(403, $this->get($path)->status(), $path);
        }
    }

    public function testAuthorSeesOnlyOwnArticlesInAdminList(): void
    {
        $author = $this->fx->user(['email' => 'author@example.test', 'role' => Role::Author->value]);
        $this->fx->article(['title' => 'МОЯ-СТАТЬЯ', 'author_id' => $author->id, 'status' => 'draft']);
        $this->fx->article(['title' => 'ЧУЖАЯ-СТАТЬЯ', 'status' => 'draft']);
        $this->loginAs('author@example.test', \Tests\Support\Fixtures::PASSWORD);

        $response = $this->get('/admin/articles');

        $this->assertSame(200, $response->status(), 'Для роли «автор» TOTP не обязателен');
        $this->assertStringContainsString('МОЯ-СТАТЬЯ', $response->body());
        $this->assertStringNotContainsString('ЧУЖАЯ-СТАТЬЯ', $response->body());
        $this->assertSame(403, $this->get('/admin/users')->status(), 'Раздел пользователей автору недоступен');
        $this->assertSame(403, $this->get('/admin/payments')->status());
    }
}
