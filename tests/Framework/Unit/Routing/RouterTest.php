<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Routing;

use Closure;
use InvalidArgumentException;
use LogicException;
use Omnitrix\Http\Exception\MethodNotAllowedException;
use Omnitrix\Http\Exception\NotFoundException;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Routing\Route;
use Omnitrix\Routing\RouteMatch;
use Omnitrix\Routing\Router;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Router::class)]
#[CoversClass(Route::class)]
#[CoversClass(RouteMatch::class)]
final class RouterTest extends TestCase
{
    private static function router(): Router
    {
        $r = new Router();
        $r->get('/', 'root');
        $r->get('/users', 'users.index')->name('users.index');
        $r->post('/users', 'users.store');
        $r->get('/users/{id:\d+}', 'users.show')->name('users.show');
        $r->map(['PUT', 'PATCH'], '/users/{id:\d+}', 'users.update');
        $r->get('/users/{id:\d+}/posts/{slug}', 'posts.show');
        $r->get('/files[/{path:.+}]', 'files');
        $r->any('/anything', 'anything');
        $r->group('/api/v1', static function (Router $r): void {
            $r->get('/health', 'health');
            $r->group('/admin', static function (Router $r): void {
                $r->delete('/users/{id}', 'admin.delete');
            }, [self::tag('admin')]);
        }, [self::tag('api')]);
        $r->compile();

        return $r;
    }

    /** @return callable(Request, Next): Response */
    private static function tag(string $name): callable
    {
        return static function (Request $request, Next $next) use ($name): Response {
            return $next($request)->header('X-' . $name, '1');
        };
    }

    public function testStaticAndDynamicRoutesMatchWithParameters(): void
    {
        $r = self::router();
        self::assertSame('root', $r->dispatch('GET', '/')->route->handler);
        self::assertSame('users.index', $r->dispatch('GET', '/users')->route->getName());

        $m = $r->dispatch('GET', '/users/42');
        self::assertSame('users.show', $m->route->handler);
        self::assertSame(['id' => '42'], $m->params);

        $m = $r->dispatch('PATCH', '/users/7');
        self::assertSame('users.update', $m->route->handler);
        self::assertSame(['PUT', 'PATCH'], $m->route->methods);

        $m = $r->dispatch('GET', '/users/1/posts/hello-world');
        self::assertSame(['id' => '1', 'slug' => 'hello-world'], $m->params);

        self::assertSame([], $r->dispatch('GET', '/files')->params);
        self::assertSame(['path' => 'a/b/c.txt'], $r->dispatch('GET', '/files/a/b/c.txt')->params);
        self::assertSame(['path' => 'sp ace'], $r->dispatch('GET', '/files/sp%20ace')->params, 'path is URL-decoded before matching');
    }

    public function testRegexConstraintAndUnknownPathsAre404(): void
    {
        $r = self::router();
        try {
            $r->dispatch('GET', '/users/abc');
            self::fail('non-numeric id matched');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status());
        }
        $this->expectException(NotFoundException::class);
        $r->dispatch('GET', '/nope');
    }

    public function testWrongMethodIs405WithAllowHeader(): void
    {
        $r = self::router();
        try {
            $r->dispatch('DELETE', '/users/5');
            self::fail('expected 405');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->status());
            self::assertSame(['Allow' => 'GET, HEAD, PATCH, PUT'], $e->headers());
            self::assertSame(['allowed' => ['GET', 'HEAD', 'PATCH', 'PUT']], $e->details());
        }
    }

    public function testHeadFallsBackToGetAndAnyCoversAllMethods(): void
    {
        $r = self::router();
        self::assertSame('users.index', $r->dispatch('HEAD', '/users')->route->handler);
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            self::assertSame('anything', $r->dispatch($method, '/anything')->route->handler);
        }
    }

    public function testGroupsPrefixPathsAndInheritMiddlewareInOrder(): void
    {
        $r = self::router();
        $health = $r->dispatch('GET', '/api/v1/health')->route;
        self::assertSame('/api/v1/health', $health->pattern);
        self::assertCount(1, $health->getMiddleware());

        $admin = $r->dispatch('DELETE', '/api/v1/admin/users/9')->route;
        self::assertSame('/api/v1/admin/users/{id}', $admin->pattern);
        self::assertCount(2, $admin->getMiddleware(), 'outer group middleware first, then inner');
        self::assertCount(10, $r->routes());
        self::assertCount(2, $r->routesFor('DELETE'));
    }

    public function testRegistrationValidation(): void
    {
        $r = new Router();
        try {
            $r->get('users', 'x');
            self::fail('pattern without leading slash accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            $r->map(['FETCH'], '/x', 'x');
            self::fail('unknown method accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            $r->group('/bad/', static fn() => null);
            self::fail('trailing slash prefix accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            $r->get('/x', 'x')->name('bad name');
            self::fail('invalid route name accepted');
        } catch (InvalidArgumentException) {
        }
        $r->compile();
        $this->expectException(LogicException::class);
        $r->get('/late', 'x');
    }

    public function testDispatchBeforeCompileIsAProgrammingError(): void
    {
        $r = new Router();
        $r->get('/x', 'x');
        $this->expectException(LogicException::class);
        $r->dispatch('GET', '/x');
    }

    public function testPipelineIsBuiltOncePerRoute(): void
    {
        $r = new Router();
        $route = $r->get('/x', 'x');
        $r->compile();
        $builds = 0;
        $factory = static function (Route $route) use (&$builds): Closure {
            $builds++;

            return static fn(Request $req): Response => Response::text('ok');
        };
        $first = $r->pipelineFor($route, $factory);
        $second = $r->pipelineFor($route, $factory);
        self::assertSame($first, $second);
        self::assertSame(1, $builds);
    }
}
