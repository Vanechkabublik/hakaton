<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\ValidationException;
use App\Http\AdminController;
use App\Taxonomy\CategoryRepository;
use App\Taxonomy\TagRepository;

/** Категории и теги. */
final class TaxonomyController extends AdminController
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly AuditLog $audit,
    ) {
    }

    public function categories(): Response
    {
        return $this->admin('taxonomy/categories', ['title' => 'Категории', 'section' => 'categories', 'items' => $this->categories->all(true)]);
    }

    public function saveCategory(Request $request, ?int $id = null): Response
    {
        $data = $this->validate($request, ['name' => 'required|string|max:100', 'slug' => 'nullable|string|max:100', 'description' => 'nullable|string|max:1000', 'color' => 'nullable|string|max:7', 'sort_order' => 'nullable|int'], ['name' => 'Название']);
        $savedId = $this->categories->save($id, $data);
        $this->audit->record($id === null ? 'category.created' : 'category.updated', $this->requireUser()->id, 'category', $savedId);

        return $this->done($request, $id === null ? 'Категория создана' : 'Категория сохранена', '/admin/categories');
    }

    public function deleteCategory(Request $request, int $id): Response
    {
        $this->categories->deleteById($id);
        $this->audit->record('category.deleted', $this->requireUser()->id, 'category', $id);

        return $this->done($request, 'Категория удалена. Материалы остались без рубрики.', '/admin/categories');
    }

    public function tags(Request $request): Response
    {
        return $this->admin('taxonomy/tags', [
            'title' => 'Теги',
            'section' => 'tags',
            'items' => $this->tags->all($request->string('q') ?: null, 500),
            'query' => $request->string('q'),
        ]);
    }

    public function saveTag(Request $request, ?int $id = null): Response
    {
        $data = $this->validate($request, ['name' => 'required|string|max:60', 'slug' => 'nullable|string|max:100'], ['name' => 'Название']);
        $this->tags->save($id, $data);

        return $this->done($request, 'Тег сохранён', '/admin/tags');
    }

    public function deleteTag(Request $request, int $id): Response
    {
        $this->tags->deleteById($id);

        return $this->done($request, 'Тег удалён', '/admin/tags');
    }

    public function mergeTag(Request $request, int $id): Response
    {
        $into = $request->int('into');
        if ($into <= 0 || $into === $id || $this->tags->find($into) === null) {
            throw new ValidationException(['into' => 'Выберите другой тег для объединения']);
        }
        $this->tags->merge($id, $into);
        $this->audit->record('tag.merged', $this->requireUser()->id, 'tag', $id, ['into' => $into]);

        return $this->done($request, 'Теги объединены', '/admin/tags');
    }
}
