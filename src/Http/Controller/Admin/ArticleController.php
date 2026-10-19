<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Article\AccessLevel;
use App\Article\Article;
use App\Article\ArticleRepository;
use App\Article\ArticleService;
use App\Article\ArticleStatus;
use App\Article\ArticleType;
use App\Article\GalleryRepository;
use App\Article\LiveEntryRepository;
use App\Article\RevisionRepository;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Crypto;
use App\Http\AdminController;
use App\Media\MediaRepository;
use App\Support\HtmlSanitizer;
use App\Support\TextDiff;
use App\Taxonomy\CategoryRepository;
use App\Taxonomy\TagRepository;
use App\User\Gate;
use App\User\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

/** Материалы: список, редактор, редакционный процесс, ревизии, live-блог. */
final class ArticleController extends AdminController
{
    public function __construct(
        private readonly ArticleRepository $articles,
        private readonly ArticleService $service,
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly UserRepository $users,
        private readonly MediaRepository $media,
        private readonly RevisionRepository $revisions,
        private readonly LiveEntryRepository $live,
        private readonly GalleryRepository $gallery,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Crypto $crypto,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $onlyOwn = $this->gate->allows($user, 'articles.edit_any') ? null : $user->id;
        $filters = $this->filters($request, ['q', 'status', 'type', 'access', 'category', 'author']);
        [$page, $offset] = $this->paging($request);
        $result = $this->articles->adminSearch($filters, self::PER_PAGE, $offset, $onlyOwn);

        return $this->admin('articles/index', [
            'title' => ($filters['status'] ?? '') === 'pending' ? 'Модерация статей' : 'Статьи',
            'section' => ($filters['status'] ?? '') === 'pending' ? 'moderation' : 'articles',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'counts' => $this->articles->countByStatus($onlyOwn),
            'categories' => $this->categories->all(),
            'authors' => $onlyOwn === null ? $this->users->listStaff() : [],
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'canBulk' => $this->gate->allows($user, 'articles.publish'),
        ]);
    }

    public function create(): Response
    {
        $this->requireUser();
        $article = new Article();
        $article->allowComments = true;

        return $this->form($article, 'Новая статья');
    }

    public function store(Request $request): Response
    {
        $user = $this->requireUser();
        $article = $this->service->create($user, $request->all());
        $this->flash('success', 'Черновик создан');

        return $this->redirect('/admin/articles/' . $article->id . '/edit');
    }

    public function edit(int $id): Response
    {
        $article = $this->load($id);

        return $this->form($article, 'Редактирование: ' . mb_strimwidth($article->title, 0, 50, '…'));
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->load($id);
        $action = $request->string('action', 'save');

        $article = $this->service->update($article, $user, $request->all());
        $message = 'Изменения сохранены';
        $note = $request->string('moderation_note');

        $target = match ($action) {
            'submit' => ArticleStatus::Pending,
            'publish' => ArticleStatus::Published,
            'schedule' => ArticleStatus::Scheduled,
            'archive' => ArticleStatus::Archived,
            'draft', 'reject' => ArticleStatus::Draft,
            default => null,
        };
        if ($target !== null) {
            $publishAt = $target === ArticleStatus::Scheduled ? $this->parseDateTime($request->string('publish_at')) : null;
            if ($target === ArticleStatus::Scheduled && $publishAt === null) {
                throw new \DomainException('Укажите дату и время публикации');
            }
            $article = $this->service->transition($article, $user, $target, $publishAt, $note);
            $message = match ($target) {
                ArticleStatus::Pending => 'Отправлено на модерацию',
                ArticleStatus::Published => 'Материал опубликован',
                ArticleStatus::Scheduled => 'Публикация запланирована на ' . \App\Support\Format::dateTime($article->publishAt),
                ArticleStatus::Archived => 'Материал перенесён в архив',
                ArticleStatus::Draft => 'Материал возвращён в черновики',
            };
        }
        $this->flash('success', $message);

        return $this->redirect('/admin/articles/' . $article->id . '/edit');
    }

    public function delete(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->articles->find($id) ?? $this->notFound();
        $this->service->delete($article, $user);
        $this->flash('success', 'Материал удалён');

        return $this->redirect('/admin/articles');
    }

    /** Массовые действия над выбранными материалами. */
    public function bulk(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->gate->allows($user, 'articles.publish')) {
            throw HttpException::forbidden();
        }
        $ids = array_values(array_unique(array_map('intval', (array) $request->input('ids', []))));
        $action = $request->string('bulk_action');
        if ($ids === [] || $action === '') {
            throw new \DomainException('Выберите материалы и действие');
        }
        $ok = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $article = $this->articles->find($id);
            if ($article === null) {
                continue;
            }
            try {
                match (true) {
                    $action === 'publish' => $this->service->transition($article, $user, ArticleStatus::Published),
                    $action === 'archive' => $this->service->transition($article, $user, ArticleStatus::Archived),
                    $action === 'draft' => $this->service->transition($article, $user, ArticleStatus::Draft),
                    $action === 'delete' => $this->service->delete($article, $user),
                    str_starts_with($action, 'category:') => $this->articles->update($id, ['category_id' => ((int) substr($action, 9)) ?: null]),
                    str_starts_with($action, 'access:') => AccessLevel::tryFrom(substr($action, 7)) !== null
                        ? $this->articles->update($id, ['access' => substr($action, 7)])
                        : throw new \DomainException('Неизвестный уровень доступа'),
                    $action === 'feature' => $this->articles->update($id, ['is_featured' => true]),
                    $action === 'unfeature' => $this->articles->update($id, ['is_featured' => false]),
                    default => throw new \DomainException('Неизвестное действие'),
                };
                ++$ok;
            } catch (\DomainException) {
                ++$failed;
            }
        }
        $this->flash($failed > 0 ? 'warning' : 'success', "Обработано: {$ok}" . ($failed > 0 ? ", пропущено: {$failed}" : ''));

        return $this->back($request, '/admin/articles');
    }

    // ---- ревизии --------------------------------------------------------------------------

    public function revisions(int $id): Response
    {
        $article = $this->load($id);

        return $this->admin('articles/revisions', [
            'title' => 'История правок',
            'section' => 'articles',
            'article' => $article,
            'revisions' => $this->revisions->list($id),
        ]);
    }

    public function revision(int $id, int $revision): Response
    {
        $article = $this->load($id);
        $row = $this->revisions->find($id, $revision) ?? $this->notFound('Версия не найдена');

        return $this->admin('articles/revision', [
            'title' => 'Версия #' . $revision,
            'section' => 'articles',
            'article' => $article,
            'revision' => $row,
            'diff' => TextDiff::blocks((string) ($row['data']['body'] ?? ''), $article->body),
            'canRestore' => $this->gate->canEditArticle($this->requireUser(), $article),
        ]);
    }

    public function restore(Request $request, int $id, int $revision): Response
    {
        $user = $this->requireUser();
        $article = $this->load($id);
        $restored = $this->service->restoreRevision($article, $revision, $user);
        $this->flash('success', 'Версия #' . $revision . ' восстановлена');

        return $this->redirect('/admin/articles/' . $restored->id . '/edit');
    }

    // ---- live-блог ------------------------------------------------------------------------

    public function addLive(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->load($id);
        $body = $this->sanitizer->clean($request->string('body'));
        if (trim(strip_tags($body)) === '') {
            throw new \DomainException('Введите текст записи');
        }
        $this->live->add($article->id, $user->id, mb_substr($request->string('title'), 0, 300), $body, $request->bool('pinned'));
        $this->flash('success', 'Запись добавлена в хронику');

        return $this->redirect('/admin/articles/' . $article->id . '/edit#live');
    }

    public function updateLive(Request $request, int $id, int $entry): Response
    {
        $article = $this->load($id);
        if ($request->string('do') === 'delete') {
            $this->live->delete($entry, $article->id);
            $this->flash('success', 'Запись удалена');
        } else {
            $this->live->update($entry, $article->id, mb_substr($request->string('title'), 0, 300), $this->sanitizer->clean($request->string('body')), $request->bool('pinned'));
            $this->flash('success', 'Запись обновлена');
        }

        return $this->redirect('/admin/articles/' . $article->id . '/edit#live');
    }

    // ---- JSON-справочники для редактора ---------------------------------------------------

    public function lookup(Request $request): Response
    {
        $this->requireUser();

        return $this->json(['ok' => true, 'items' => $this->articles->lookup($request->string('q'), $request->int('except') ?: null)]);
    }

    public function tagSuggest(Request $request): Response
    {
        $this->requireUser();

        return $this->json(['ok' => true, 'items' => array_map(static fn ($t): string => $t->name, $this->tags->all($request->string('q') ?: null, 12))]);
    }

    // ---- внутреннее -----------------------------------------------------------------------

    private function load(int $id): Article
    {
        $user = $this->requireUser();
        $article = $this->articles->find($id) ?? $this->notFound('Материал не найден');
        if (!$this->gate->canEditArticle($user, $article) && !$this->gate->canViewUnpublished($user, $article)) {
            throw HttpException::forbidden();
        }

        return $article;
    }

    private function form(Article $article, string $title): Response
    {
        $user = $this->requireUser();
        $isEditor = $this->gate->allows($user, 'articles.edit_any');
        $isNew = $article->id === 0;
        $cover = $article->coverMediaId !== null ? $this->media->find($article->coverMediaId) : null;
        $tagNames = $isNew ? '' : implode(', ', array_map(static fn ($t): string => $t->name, $this->tags->forArticle($article->id)));

        return $this->admin('articles/form', [
            'title' => $title,
            'section' => 'articles',
            'article' => $article,
            'isNew' => $isNew,
            'types' => ArticleType::cases(),
            'accessLevels' => AccessLevel::cases(),
            'categories' => $this->categories->all(),
            'tagNames' => $tagNames,
            'cover' => $cover,
            'related' => $isNew ? [] : $this->articles->relatedChoices($article->id),
            'gallery' => $isNew ? [] : $this->gallery->forArticle($article->id),
            'liveEntries' => $isNew ? [] : $this->live->list($article->id),
            'authors' => $isEditor ? $this->users->listStaff() : [],
            'canEdit' => $isNew || $this->gate->canEditArticle($user, $article),
            'canPublish' => $this->gate->allows($user, 'articles.publish'),
            'isEditor' => $isEditor,
            'previewUrl' => $isNew ? null : '/preview/' . $article->id,
            'signedPreview' => $isNew ? null : '/preview/' . $article->id . '?t=' . $this->crypto->signedToken('preview:' . $article->id, 7 * 86400),
        ]);
    }

    private function parseDateTime(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
