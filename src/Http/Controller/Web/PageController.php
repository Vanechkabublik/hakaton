<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Core\Http\Response;
use App\Http\Controller;
use App\Page\PageRepository;
use App\Seo\SeoService;

final class PageController extends Controller
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly SeoService $seo,
    ) {
    }

    public function show(string $slug): Response
    {
        $page = $this->pages->findBySlug($slug) ?? $this->notFound();
        $this->meta($this->seo->page($page->title, $page->seoDescription, '/' . $page->slug));

        return $this->view('web/page', ['page' => $page]);
    }
}
