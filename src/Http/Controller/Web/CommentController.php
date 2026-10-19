<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Article\ArticleRepository;
use App\Comment\CommentRepository;
use App\Comment\CommentService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Support\TextFormatter;

/** AJAX-комментарии: публикация, правка (5 минут), лайки, жалобы. */
final class CommentController extends Controller
{
    public function __construct(
        private readonly CommentService $service,
        private readonly CommentRepository $comments,
        private readonly ArticleRepository $articles,
    ) {
    }

    public function store(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $article = $this->articles->find($id) ?? $this->notFound();
        $parent = $request->int('parent_id');
        $comment = $this->service->post($user, $article, $request->string('body'), $parent > 0 ? $parent : null, $request->ip());
        $fresh = $this->comments->find($comment->id) ?? $comment;

        return $this->json([
            'ok' => true,
            'status' => $fresh->status,
            'message' => $fresh->isApproved() ? null : 'Комментарий отправлен на проверку и появится после модерации',
            'html' => $this->fragment('partials/comment', ['comment' => $fresh, 'viewer' => $user, 'withChildren' => false]),
            'parent_id' => $fresh->parentId,
        ], 201);
    }

    public function update(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $comment = $this->comments->find($id) ?? $this->notFound();
        $updated = $this->service->edit($user, $comment, $request->string('body'));

        return $this->json([
            'ok' => true,
            'body_html' => TextFormatter::commentHtml($updated->body),
            'body' => $updated->body,
            'status' => $updated->status,
        ]);
    }

    public function like(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $comment = $this->comments->find($id) ?? $this->notFound();

        return $this->json(['ok' => true] + $this->service->toggleLike($user, $comment));
    }

    public function report(Request $request, int $id): Response
    {
        $user = $this->requireUser();
        $comment = $this->comments->find($id) ?? $this->notFound();
        $added = $this->service->report($user, $comment, $request->string('reason', 'other'), $request->string('details'));

        return $this->json(['ok' => true, 'message' => $added ? 'Спасибо, жалоба отправлена модераторам' : 'Вы уже жаловались на этот комментарий']);
    }
}
