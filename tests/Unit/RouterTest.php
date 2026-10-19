<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $router = new Router();
        $router->get('/', fn () => 'home')->name('home');
        $router->get('/article/{slug}', fn () => 'article')->name('article');
        $router->get('/user/{id:\d+}', fn () => 'user')->name('user');
        $router->post('/login', fn () => 'login')->name('login.post');
        $router->get('/login', fn () => 'login form')->name('login');
        $router->group(['prefix' => '/admin', 'middleware' => ['staff']], function (Router $r): void {
            $r->get('/dashboard', fn () => 'dash')->name('admin.dashboard')->middleware('can:admin');
        });

        return $router;
    }

    public function testMatchesStaticAndParameterRoutes(): void
    {
        $router = $this->router();

        [$route, $params] = $router->match(Request::create('GET', '/article/privet-mir'));
        $this->assertSame(['slug' => 'privet-mir'], $params);
        $this->assertSame('/article/{slug}', $route->pattern);

        [, $params] = $router->match(Request::create('GET', '/user/42'));
        $this->assertSame(['id' => '42'], $params);
    }

    public function testParameterConstraintRejectsNonMatching(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $this->router()->match(Request::create('GET', '/user/abc'));
    }

    public function testUnknownPathIs404(): void
    {
        try {
            $this->router()->match(Request::create('GET', '/nope'));
            $this->fail('Ожидалось исключение');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        try {
            $this->router()->match(Request::create('DELETE', '/login'));
            $this->fail('Ожидалось исключение');
        } catch (HttpException $e) {
            $this->assertSame(405, $e->status);
            $allowed = array_map('trim', explode(',', $e->headers['Allow']));
            sort($allowed);
            $this->assertSame(['GET', 'HEAD', 'POST'], $allowed);
        }
    }

    public function testGroupPrefixAndMiddleware(): void
    {
        [$route] = $this->router()->match(Request::create('GET', '/admin/dashboard'));
        $this->assertSame(['staff', 'can:admin'], $route->middlewareList());
    }

    public function testUrlGeneration(): void
    {
        $router = $this->router();
        $this->assertSame('/article/privet-mir', $router->url('article', ['slug' => 'privet-mir']));
        $this->assertSame('/article/a%20b?page=2', $router->url('article', ['slug' => 'a b'], ['page' => 2]));
        $this->assertSame('/user/7', $router->url('user', ['id' => 7]));
        $this->assertSame('/', $router->url('home'));
    }

    public function testUrlRequiresAllParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router()->url('article');
    }

    public function testUrlForUnknownRouteFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->router()->url('missing');
    }

    public function testCyrillicSlugMatches(): void
    {
        [, $params] = $this->router()->match(Request::create('GET', '/article/новости'));
        $this->assertSame('новости', $params['slug']);
    }
}
