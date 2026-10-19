<?php

declare(strict_types=1);

namespace App\Http\Controller\Admin;

use App\Analytics\StatsService;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Http\AdminController;
use App\User\Gate;

final class DashboardController extends AdminController
{
    public function __construct(
        private readonly StatsService $stats,
        private readonly Gate $gate,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        if (!$this->gate->allows($user, 'dashboard.view')) {
            return $this->redirect('/admin/articles');
        }
        $stats = $this->stats->dashboard($request->int('days', 30), $this->gate->allows($user, 'finance.view'));

        return $this->admin('dashboard', [
            'title' => 'Дашборд',
            'section' => 'dashboard',
            'stats' => $stats,
            'finance' => $this->gate->allows($user, 'finance.view'),
        ]);
    }
}
