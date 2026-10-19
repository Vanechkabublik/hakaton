<?php

declare(strict_types=1);

namespace App\Article;

use App\Audit\AuditLog;
use App\Core\Database\Database;
use App\Core\Http\ValidationException;
use App\Core\Queue\Queue;
use App\Notification\ArticlePublishedJob;
use App\Support\HtmlSanitizer;
use App\Support\Slug;
use App\Taxonomy\TagRepository;
use App\User\Gate;
use App\User\Role;
use App\User\User;
use App\User\UserRepository;
use DateTimeImmutable;

/**
 * Бизнес-логика материалов: создание и правка с очисткой HTML и ревизиями,
 * редакционный процесс (черновик → модерация → публикация → архив), отложенная публикация.
 */
final class ArticleService
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly TagRepository $tags,
        private readonly RevisionRepository $revisions,
        private readonly GalleryRepository $gallery,
        private readonly HtmlSanitizer $sanitizer,
        private readonly UserRepository $users,
        private readonly Database $db,
        private readonly Queue $queue,
        private readonly AuditLog $audit,
        private readonly Gate $gate,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @throws ValidationException
     */
    public function create(User $actor, array $input): Article
    {
        $data = $this->prepare($input, $actor, null);
        $title = (string) $data['title'];
        $slug = isset($input['slug']) && trim((string) $input['slug']) !== ''
            ? Slug::unique((string) $input['slug'], fn (string $s): bool => $this->articles->slugExists($s))
            : Slug::unique($title, fn (string $s): bool => $this->articles->slugExists($s), 'material');

        $id = $this->db->transaction(function () use ($data, $slug, $actor, $input): int {
            $id = $this->articles->create($data + [
                'slug' => $slug,
                'status' => ArticleStatus::Draft->value,
                'author_id' => $this->resolveAuthorId($actor, $input),
            ]);
            $this->syncRelations($id, $input);

            return $id;
        });
        $article = $this->articles->find($id) ?? throw new \RuntimeException('Статья не создана');
        $this->revisions->add($id, $actor->id, $this->snapshot($article), 'Создание');
        $this->audit->record('article.created', $actor->id, 'article', $id, ['title' => $article->title]);

        return $article;
    }

    /**
     * @param array<string,mixed> $input
     * @throws ValidationException
     */
    public function update(Article $article, User $actor, array $input, string $note = 'Правка'): Article
    {
        if (!$this->gate->canEditArticle($actor, $article)) {
            throw new \DomainException('Нет прав на редактирование этого материала');
        }
        $data = $this->prepare($input, $actor, $article);
        if ($this->gate->allows($actor, 'articles.edit_any') && isset($input['slug']) && trim((string) $input['slug']) !== '' && $input['slug'] !== $article->slug) {
            $slug = Slug::make((string) $input['slug']);
            if ($slug !== '' && !$this->articles->slugExists($slug, $article->id)) {
                $data['slug'] = $slug;
            }
        }
        if ($this->gate->allows($actor, 'articles.edit_any') && !empty($input['author_id'])) {
            $author = $this->users->find((int) $input['author_id']);
            if ($author !== null && $author->isStaff()) {
                $data['author_id'] = $author->id;
            }
        }

        $this->db->transaction(function () use ($article, $data, $input): void {
            $this->articles->update($article->id, $data);
            $this->syncRelations($article->id, $input);
        });
        $fresh = $this->articles->find($article->id) ?? $article;
        $this->saveRevisionIfChanged($fresh, $actor, $note);
        $this->audit->record('article.updated', $actor->id, 'article', $article->id);

        return $fresh;
    }

    /**
     * Перевод в новый статус по правилам редакционного процесса.
     *
     * @throws \DomainException если переход запрещён
     */
    public function transition(Article $article, User $actor, ArticleStatus $to, ?DateTimeImmutable $publishAt = null, string $note = ''): Article
    {
        $from = $article->status;
        if ($from === $to) {
            return $article;
        }
        if (!$from->canTransitionTo($to)) {
            throw new \DomainException("Нельзя перевести материал из «{$from->label()}» в «{$to->label()}»");
        }
        if (!$this->canActorTransition($actor, $article, $from, $to)) {
            throw new \DomainException('Недостаточно прав для этого действия');
        }

        $changes = ['status' => $to->value];
        switch ($to) {
            case ArticleStatus::Pending:
                $this->assertPublishable($article);
                $changes['submitted_at'] = new DateTimeImmutable();
                break;
            case ArticleStatus::Scheduled:
                $this->assertPublishable($article);
                if ($publishAt === null || $publishAt <= new DateTimeImmutable('+1 minute')) {
                    throw new \DomainException('Укажите время публикации в будущем');
                }
                $changes['publish_at'] = $publishAt;
                $changes['moderated_by'] = $actor->id;
                break;
            case ArticleStatus::Published:
                $this->assertPublishable($article);
                if ($from !== ArticleStatus::Archived || $article->publishedAt === null) {
                    $changes['published_at'] = new DateTimeImmutable();
                }
                $changes['publish_at'] = null;
                $changes['archived_at'] = null;
                $changes['moderated_by'] = $actor->id;
                break;
            case ArticleStatus::Archived:
                $changes['archived_at'] = new DateTimeImmutable();
                break;
            case ArticleStatus::Draft:
                $changes['publish_at'] = null;
                if ($note !== '' && $actor->id !== $article->authorId) {
                    $changes['moderation_note'] = mb_substr($note, 0, 2000);
                }
                break;
        }
        $this->articles->update($article->id, $changes);
        $fresh = $this->articles->find($article->id) ?? $article;
        $this->revisions->add($fresh->id, $actor->id, $this->snapshot($fresh), $from->label() . ' → ' . $to->label());
        $this->audit->record('article.' . $to->value, $actor->id, 'article', $article->id, ['from' => $from->value, 'note' => $note]);

        if ($to === ArticleStatus::Published && $from !== ArticleStatus::Archived) {
            $this->queue->push(ArticlePublishedJob::class, ['article_id' => $fresh->id]);
        }

        return $fresh;
    }

    /** Опубликовать запланированные материалы (вызывается планировщиком). */
    public function publishDue(): int
    {
        $ids = $this->articles->publishDue();
        foreach ($ids as $id) {
            $this->audit->record('article.published_scheduled', null, 'article', $id);
            $this->queue->push(ArticlePublishedJob::class, ['article_id' => $id]);
        }

        return count($ids);
    }

    public function delete(Article $article, User $actor): void
    {
        if (!$this->gate->allows($actor, 'articles.delete') && !($article->authorId === $actor->id && $article->status === ArticleStatus::Draft)) {
            throw new \DomainException('Недостаточно прав для удаления');
        }
        $this->articles->deleteById($article->id);
        $this->audit->record('article.deleted', $actor->id, 'article', $article->id, ['title' => $article->title]);
    }

    /**
     * @throws ValidationException
     */
    public function restoreRevision(Article $article, int $revisionId, User $actor): Article
    {
        $revision = $this->revisions->find($article->id, $revisionId);
        if ($revision === null) {
            throw new ValidationException(['revision' => 'Версия не найдена']);
        }
        $data = $revision['data'];
        $input = [
            'title' => $data['title'] ?? $article->title,
            'subtitle' => $data['subtitle'] ?? '',
            'lead' => $data['lead'] ?? '',
            'body' => $data['body'] ?? '',
            'type' => $data['type'] ?? $article->type->value,
            'access' => $data['access'] ?? $article->access->value,
            'category_id' => $data['category_id'] ?? null,
            'tags' => implode(', ', (array) ($data['tags'] ?? [])),
            'cover_media_id' => $data['cover_media_id'] ?? null,
            'cover_caption' => $data['cover_caption'] ?? '',
            'seo_title' => $data['seo_title'] ?? '',
            'seo_description' => $data['seo_description'] ?? '',
            'is_featured' => $article->isFeatured,
            'allow_comments' => $article->allowComments,
            'related_ids' => array_column($this->articles->relatedChoices($article->id), 'id'),
        ];

        return $this->update($article, $actor, $input, 'Восстановление версии #' . $revisionId);
    }

    /** @return array<string,mixed> */
    public function snapshot(Article $article): array
    {
        return [
            'title' => $article->title,
            'subtitle' => $article->subtitle,
            'lead' => $article->lead,
            'body' => $article->body,
            'type' => $article->type->value,
            'access' => $article->access->value,
            'status' => $article->status->value,
            'category_id' => $article->categoryId,
            'tags' => array_map(static fn ($t): string => $t->name, $this->tags->forArticle($article->id)),
            'cover_media_id' => $article->coverMediaId,
            'cover_caption' => $article->coverCaption,
            'seo_title' => $article->seoTitle,
            'seo_description' => $article->seoDescription,
        ];
    }

    private function saveRevisionIfChanged(Article $article, User $actor, string $note): void
    {
        $snapshot = $this->snapshot($article);
        $last = $this->revisions->latest($article->id);
        unset($snapshot['status']);
        $compare = $last;
        if (is_array($compare)) {
            unset($compare['status']);
        }
        if ($compare === null || json_encode($compare) !== json_encode($snapshot)) {
            $this->revisions->add($article->id, $actor->id, $this->snapshot($article), $note);
        }
    }

    /**
     * Нормализация и валидация данных формы.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     * @throws ValidationException
     */
    private function prepare(array $input, User $actor, ?Article $existing): array
    {
        $errors = [];
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'Укажите заголовок';
        } elseif (mb_strlen($title) > 300) {
            $errors['title'] = 'Заголовок не длиннее 300 символов';
        }
        $type = ArticleType::tryFrom((string) ($input['type'] ?? '')) ?? $existing?->type ?? ArticleType::News;
        $access = AccessLevel::tryFrom((string) ($input['access'] ?? '')) ?? $existing?->access ?? AccessLevel::Free;
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $body = $this->sanitizer->clean((string) ($input['body'] ?? ''));
        $isEditor = $this->gate->allows($actor, 'articles.edit_any');
        $data = [
            'title' => $title,
            'subtitle' => mb_substr(trim((string) ($input['subtitle'] ?? '')), 0, 500),
            'lead' => trim(strip_tags((string) ($input['lead'] ?? ''))),
            'body' => $body,
            'type' => $type->value,
            'access' => $access->value,
            'category_id' => !empty($input['category_id']) ? (int) $input['category_id'] : null,
            'cover_media_id' => !empty($input['cover_media_id']) ? (int) $input['cover_media_id'] : null,
            'cover_caption' => mb_substr(trim(strip_tags((string) ($input['cover_caption'] ?? ''))), 0, 300),
            'seo_title' => mb_substr(trim(strip_tags((string) ($input['seo_title'] ?? ''))), 0, 200),
            'seo_description' => mb_substr(trim(strip_tags((string) ($input['seo_description'] ?? ''))), 0, 400),
            'allow_comments' => !empty($input['allow_comments']),
            'reading_minutes' => ReadingTime::minutes($body),
            'is_live' => $type === ArticleType::Liveblog && !empty($input['is_live']),
        ];
        if ($isEditor) {
            $data['is_featured'] = !empty($input['is_featured']);
        }
        if ($existing === null && !isset($input['allow_comments'])) {
            $data['allow_comments'] = true;
        }

        return $data;
    }

    /** @param array<string,mixed> $input */
    private function syncRelations(int $articleId, array $input): void
    {
        $names = array_filter(array_map('trim', explode(',', (string) ($input['tags'] ?? ''))));
        $this->articles->syncTags($articleId, $this->tags->resolveNames(array_slice($names, 0, 15)));
        $related = array_map('intval', (array) ($input['related_ids'] ?? []));
        $this->articles->syncRelated($articleId, array_slice($related, 0, 8));
        if (isset($input['gallery']) && is_array($input['gallery'])) {
            $items = [];
            foreach ($input['gallery'] as $item) {
                if (is_array($item) && !empty($item['media_id'])) {
                    $items[] = ['media_id' => (int) $item['media_id'], 'caption' => (string) ($item['caption'] ?? '')];
                }
            }
            $this->gallery->replace($articleId, $items);
        }
    }

    private function resolveAuthorId(User $actor, array $input): int
    {
        if ($this->gate->allows($actor, 'articles.edit_any') && !empty($input['author_id'])) {
            $author = $this->users->find((int) $input['author_id']);
            if ($author !== null && $author->isStaff()) {
                return $author->id;
            }
        }

        return $actor->id;
    }

    private function assertPublishable(Article $article): void
    {
        if (trim($article->title) === '' || trim(strip_tags($article->body)) === '' && $article->type !== ArticleType::Liveblog && $article->type !== ArticleType::Photo) {
            throw new \DomainException('Нельзя отправить материал без заголовка и текста');
        }
    }

    private function canActorTransition(User $actor, Article $article, ArticleStatus $from, ArticleStatus $to): bool
    {
        if ($this->gate->allows($actor, 'articles.publish')) {
            return true;
        }
        // Автор: отправить свой черновик на модерацию и отозвать обратно
        return $actor->role === Role::Author
            && $article->authorId === $actor->id
            && (
                ($from === ArticleStatus::Draft && $to === ArticleStatus::Pending)
                || ($from === ArticleStatus::Pending && $to === ArticleStatus::Draft)
            );
    }
}
