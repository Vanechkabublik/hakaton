<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Core\Http\RequestContext;
use App\Core\Router;
use App\Core\Security\Session;
use App\Core\View\View;

/** Общие зависимости контроллеров (внедряются контейнером через Controller::setContext). */
final class ControllerContext
{
    public function __construct(
        public readonly View $view,
        public readonly Session $session,
        public readonly Auth $auth,
        public readonly Router $router,
        public readonly ViewComposer $composer,
        public readonly RequestContext $requestContext,
    ) {
    }
}
