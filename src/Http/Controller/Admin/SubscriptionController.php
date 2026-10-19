<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Billing\SubscriptionRepository;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\AdminController;

/** Список подписок: активные и истёкшие. */
final class SubscriptionController extends AdminController
{
    public function __construct(private readonly SubscriptionRepository $subs)
    {
    }

    public function index(Request $request): Response
    {
        $filters = $this->filters($request, ['q', 'status', 'tier', 'source']);
        $filters['status'] = $filters['status'] ?? 'live';
        [$page, $offset] = $this->paging($request);
        $result = $this->subs->adminSearch($filters, self::PER_PAGE, $offset);

        return $this->admin('subscriptions/index', [
            'title' => 'Подписки',
            'section' => 'subscriptions',
            'items' => $result['items'],
            'total' => $result['total'],
            'filters' => $filters,
            'page' => $page,
            'pages' => (int) max(1, ceil($result['total'] / self::PER_PAGE)),
        ]);
    }
}
