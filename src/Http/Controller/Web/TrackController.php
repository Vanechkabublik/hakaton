<?php

declare(strict_types=1);

namespace App\Http\Controller\Web;

use App\Analytics\ViewTracker;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\Controller;
use App\Paywall\VisitorResolver;

/** Маяк прогресса чтения (navigator.sendBeacon): дочитывания и время на странице. */
final class TrackController extends Controller
{
    public function __construct(
        private readonly ViewTracker $views,
        private readonly VisitorResolver $visitors,
    ) {
    }

    public function progress(Request $request): Response
    {
        $articleId = $request->int('article_id');
        $cookie = $request->cookie(VisitorResolver::COOKIE);
        if ($articleId > 0 && $cookie !== null && preg_match('/^[a-f0-9]{32}$/', $cookie)) {
            $visitor = $this->visitors->resolve($request);
            $this->views->recordProgress($articleId, $visitor, $request->int('progress'), $request->int('seconds'));
        }

        return Response::noContent();
    }
}
