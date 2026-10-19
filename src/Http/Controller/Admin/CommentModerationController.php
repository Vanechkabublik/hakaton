<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Comment\CommentRepository;
use App\Comment\CommentService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\AdminController;

/** Модерация комментариев: одобрить / отклонить / спам, жалобы, массовые действия. */
final class CommentModerationController extends AdminController
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly CommentService $service,
    ) {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['status', 'q']);
        $filters['reported'] = $request->query('reported') === '1' ? '1' : '';
        [$page, $offset] = $this->paging($request);
        $result = $this->comments->adminSearch($filters, self::PER_PAGE, $offset);

        return $this->admin('comments/index', [
            'title' => 'Комментарии',
            'section' => 'comments',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => array_filter($filters),
            'counts' => $this->comments->countByStatus(),
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
            'reports' => $this->reportsFor($result['items']),
        ]);
    }

    public function moderate(Request $request, int $id): Response
    {
        $comment = $this->comments->find($id) ?? $this->notFound();
        $this->service->moderate($comment, $request->string('status'), $this->requireUser());

        return $this->back($request, '/admin/comments');
    }

    public function bulk(Request $request): Response
    {
        $ids = array_map('intval', (array) $request->input('ids', []));
        $count = $this->service->bulkModerate($ids, $request->string('bulk_action'), $this->requireUser());
        $this->flash('success', "Обработано комментариев: {$count}");

        return $this->back($request, '/admin/comments');
    }

    /** @param list<\App\Comment\Comment> $items @return array<int,list<array<string,mixed>>> */
    private function reportsFor(array $items): array
    {
        $map = [];
        foreach ($items as $comment) {
            if ($comment->reportsCount > 0) {
                $map[$comment->id] = $this->comments->reportsFor($comment->id);
            }
        }

        return $map;
    }
}
