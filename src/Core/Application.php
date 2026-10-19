<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Console\ConsoleApplication;
use App\Core\Database\Database;
use App\Core\Schedule\Scheduler;
use App\Billing\Gateway\PaymentGateway;
use App\Billing\Gateway\YooKassaClient;
use App\Core\Http\CurlHttpClient;
use App\Core\Http\HttpClient;
use App\Core\Http\HttpException;
use App\Core\Http\Middleware;
use App\Core\Http\Request;
use App\Core\Http\RequestContext;
use App\Core\Http\Response;
use App\Core\Security\Session;
use App\Http\Controller;
use App\Http\ControllerContext;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\HandleErrors;
use App\Http\Middleware\RateLimit;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireAbility;
use App\Http\Middleware\RequireAuth;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyCsrf;
use App\Support\Format;
use Throwable;

/** Ядро приложения: контейнер, маршруты, цепочка middleware, консольные команды. */
final class Application
{
    /** @var array<string,class-string<Middleware>> */
    private const MIDDLEWARE_ALIASES = [
        'auth' => RequireAuth::class,
        'guest' => RedirectIfAuthenticated::class,
        'staff' => RequireStaff::class,
        'can' => RequireAbility::class,
        'rate' => RateLimit::class,
    ];

    /** Глобальные middleware (снаружи внутрь). */
    private const GLOBAL_MIDDLEWARE = [
        SecurityHeaders::class,
        HandleErrors::class,
        Authenticate::class,
        VerifyCsrf::class,
    ];

    private readonly Container $container;
    private readonly Config $config;
    private readonly Router $router;

    public function __construct(private readonly string $basePath)
    {
        $this->config = Config::load($basePath);
        $this->container = new Container();
        $this->router = new Router();

        $this->container->instance(Container::class, $this->container);
        $this->container->instance(Config::class, $this->config);
        $this->container->instance(Router::class, $this->router);
        $this->container->instance(self::class, $this);
        $this->container->bind(HttpClient::class, CurlHttpClient::class);
        $this->container->bind(PaymentGateway::class, YooKassaClient::class);
        $this->container->singleton(Scheduler::class, function (Container $c) use ($basePath): Scheduler {
            $scheduler = new Scheduler($c, $c->get(Database::class), $c->get(Logger::class));
            foreach (require $basePath . '/config/schedule.php' as $task) {
                $scheduler->add($task);
            }

            return $scheduler;
        });

        date_default_timezone_set($this->config->timezone());
        Format::setTimezone($this->config->timezone());
        mb_internal_encoding('UTF-8');
        ini_set('display_errors', $this->config->debug() ? '1' : '0');
        error_reporting(E_ALL);

        $this->loadRoutes();
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function loadRoutes(): void
    {
        foreach (['web', 'account', 'billing', 'admin', 'api'] as $file) {
            $path = $this->basePath . '/routes/' . $file . '.php';
            if (is_file($path)) {
                $register = require $path;
                $register($this->router);
            }
        }
    }

    public function run(): void
    {
        $request = Request::fromGlobals();
        $response = $this->handle($request);
        if ($request->isMethod('HEAD')) {
            $response = $response->withBody('');
        }
        $response->send();
    }

    public function handle(Request $request): Response
    {
        $this->container->instance(Request::class, $request);
        $this->container->get(RequestContext::class)->set($request);
        $session = $this->container->get(Session::class);

        try {
            $session->load($request);
        } catch (Throwable $e) {
            $this->container->get(Logger::class)->error('Сессия недоступна', ['exception' => $e]);
        }

        $pipeline = $this->buildPipeline(self::GLOBAL_MIDDLEWARE, fn (Request $r): Response => $this->dispatch($r));
        try {
            $response = $pipeline($request);
        } catch (Throwable $e) {
            // Последний рубеж: ошибка в самих обработчиках ошибок
            $this->container->get(Logger::class)->critical('Сбой обработки запроса', ['exception' => $e]);
            $response = Response::html('<!doctype html><meta charset="utf-8"><title>Ошибка</title><h1>Сервис временно недоступен</h1>', 500);
        }

        try {
            $session->commit($request, $response);
        } catch (Throwable $e) {
            $this->container->get(Logger::class)->error('Не удалось сохранить сессию', ['exception' => $e]);
        }

        return $response;
    }

    /**
     * @param list<string> $stack
     * @param callable(Request):Response $core
     * @return callable(Request):Response
     */
    private function buildPipeline(array $stack, callable $core): callable
    {
        $next = $core;
        foreach (array_reverse($stack) as $spec) {
            $next = function (Request $request) use ($spec, $next): Response {
                [$class, $args] = $this->parseMiddleware($spec);
                $middleware = $this->container->get($class);
                if (!$middleware instanceof Middleware) {
                    throw new \RuntimeException("{$class} не реализует Middleware");
                }

                return $middleware->handle($request, $next, $args);
            };
        }

        return $next;
    }

    /** @return array{0:class-string<Middleware>,1:list<string>} */
    private function parseMiddleware(string $spec): array
    {
        if (class_exists($spec)) {
            return [$spec, []];
        }
        [$alias, $arguments] = array_pad(explode(':', $spec, 2), 2, '');
        $class = self::MIDDLEWARE_ALIASES[$alias] ?? throw new \RuntimeException("Неизвестный middleware «{$alias}»");

        return [$class, $arguments === '' ? [] : explode(',', $arguments)];
    }

    private function dispatch(Request $request): Response
    {
        [$route, $params] = $this->router->match($request);
        $request->withAttribute('route_params', $params);

        $core = function (Request $r) use ($route, $params): Response {
            return $this->normalize($this->callHandler($route, $params, $r));
        };

        return $this->buildPipeline($route->middlewareList(), $core)($request);
    }

    /** @param array<string,string> $params */
    private function callHandler(Route $route, array $params, Request $request): mixed
    {
        $handler = $route->handler;
        if ($handler instanceof \Closure) {
            return $this->container->call($handler, $params);
        }
        [$class, $method] = $handler;
        $controller = $this->container->get($class);
        if ($controller instanceof Controller) {
            $controller->setContext($this->container->get(ControllerContext::class));
        }

        return $this->container->call([$controller, $method], $params);
    }

    private function normalize(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            is_string($result) => Response::html($result),
            is_array($result) => Response::json($result),
            $result === null => Response::noContent(),
            default => throw new HttpException(500, 'Контроллер вернул неподдерживаемый тип'),
        };
    }

    public function console(): ConsoleApplication
    {
        $console = new ConsoleApplication($this->container);
        $commands = require $this->basePath . '/config/commands.php';
        foreach ($commands as $class) {
            $console->register($class);
        }

        return $console;
    }
}
