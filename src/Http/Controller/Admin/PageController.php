<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Audit\AuditLog;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\AdminController;
use App\Page\PageRepository;
use App\Support\HtmlSanitizer;

/** Служебные страницы: о проекте, контакты, реклама, правила, соглашение, политика. */
final class PageController extends AdminController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditLog $audit,
    ) {
    }

    public function index(): Response
    {
        return $this->admin('pages/index', ['title' => 'Страницы', 'section' => 'pages', 'items' => $this->pages->all()]);
    }

    public function edit(int $id): Response
    {
        $page = $this->pages->find($id) ?? $this->notFound();

        return $this->admin('pages/edit', ['title' => 'Страница: ' . $page->title, 'section' => 'pages', 'page' => $page]);
    }

    public function update(Request $request, int $id): Response
    {
        $page = $this->pages->find($id) ?? $this->notFound();
        $this->validate($request, ['title' => 'required|string|max:200'], ['title' => 'Заголовок']);
        $this->pages->savePage($page->id, $request->all(), $this->sanitizer);
        $this->audit->record('page.updated', $this->requireUser()->id, 'page', $page->id);
        $this->flash('success', 'Страница сохранена');

        return $this->redirect('/admin/pages/' . $page->id . '/edit');
    }
}
