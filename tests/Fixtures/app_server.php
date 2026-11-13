<?php

declare(strict_types=1);

/*
 * Application fixture: routes, groups, middleware and optional modules.
 * Env: OMNITRIX_FIXTURE_PORT, OMNITRIX_FIXTURE_DEBUG (0|1).
 */

use Omnitrix\Application;
use Omnitrix\Core\Concurrency\TimeoutException;
use Omnitrix\Core\Process\CpuException;
use Omnitrix\Core\Process\CpuJobException;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Facades\Async;
use Omnitrix\Facades\Cache;
use Omnitrix\Facades\Cpu;
use Omnitrix\Facades\DB;
use Omnitrix\Facades\Http;
use Omnitrix\Facades\Redis as RedisFacade;
use Omnitrix\Http\Cookie;
use Omnitrix\Http\Exception\HttpException;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Http\Stream;
use Omnitrix\Routing\Router;
use OpenSwoole\Coroutine;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Next $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);

        return $response->header('X-Elapsed-Us', (string) (int) ((hrtime(true) - $start) / 1000))->header('X-Order', ($response->getHeader('X-Order') ?? '') . 'timing;');
    }
}

final class UsersController
{
    public function show(Request $request): Response
    {
        return Response::json(['id' => $request->routeInt('id'), 'route' => $request->routeName(), 'user' => $request->attribute('auth.user')]);
    }

    public function store(Request $request): Response
    {
        $data = $request->jsonObject();

        return Response::json(['created' => $data], 201)->cookie(new Cookie('last_created', 'u'))->header('Location', '/api/users/1');
    }
}

$config = [
    'server' => [
        'host' => '127.0.0.1',
        'port' => (int) $env('OMNITRIX_FIXTURE_PORT', '18340'),
        'workers' => 2,
        'max_wait_time' => 5,
        'log_level' => 'error',
        'log_file' => dirname(__DIR__, 2) . '/var/log/fixture-app.log',
        'backlog' => 1024,
    ],
    'http' => ['request_timeout' => 5, 'max_body_size' => 128 * 1024],
    'debug' => $env('OMNITRIX_FIXTURE_DEBUG', '0') === '1',
];
if ($env('OMNITRIX_FIXTURE_REDIS', '0') === '1') {
    $config['redis'] = ['host' => $env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_REDIS_PORT', '63790'), 'prefix' => 'omnitrix-app:', 'pool' => ['max' => 8]];
    $config['cache'] = ['prefix' => 'cache:', 'ttl' => 60];
}
if ($env('OMNITRIX_FIXTURE_DB', '0') === '1') {
    $config['database'] = [
        'driver' => 'mariadb', 'host' => $env('OMNITRIX_TEST_DB_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_DB_PORT', '33306'),
        'database' => $env('OMNITRIX_TEST_DB_NAME', 'omnitrix_test'), 'username' => $env('OMNITRIX_TEST_DB_USER', 'root'), 'password' => $env('OMNITRIX_TEST_DB_PASS', ''),
        'pool' => ['min' => 2, 'max' => 8, 'acquire_timeout' => 2.0],
    ];
}
$app = Application::create($config);
if ($env('OMNITRIX_FIXTURE_CPU', '0') === '1') {
    $app->cpu(workers: 2, timeout: 10.0, maxPending: 16, jobs: [
        'primes' => static function (array $args): array {
            $limit = is_int($args['limit'] ?? null) ? $args['limit'] : 1000;
            $count = 0;
            for ($n = 2; $n <= $limit; $n++) {
                $prime = true;
                for ($d = 2; $d * $d <= $n; $d++) {
                    if ($n % $d === 0) {
                        $prime = false;
                        break;
                    }
                }
                $count += $prime ? 1 : 0;
            }

            return ['limit' => $limit, 'primes' => $count, 'pid' => getmypid()];
        },
        'spin' => static function (array $args): string {
            $until = microtime(true) + (is_float($args['seconds'] ?? null) || is_int($args['seconds'] ?? null) ? (float) $args['seconds'] : 1.0);
            $x = 0;
            while (microtime(true) < $until) {
                $x++;
            }

            return 'spun';
        },
        'fail' => static function (): never {
            throw new InvalidArgumentException('bad input for job');
        },
        'crash' => static function (): never {
            posix_kill((int) getmypid(), SIGKILL);
            exit(1);
        },
    ]);
    $app->get('/cpu/{job}', static function (Request $r): Response {
        $job = is_string($r->route('job')) ? $r->route('job') : '';
        $args = [];
        foreach ($r->query() as $k => $v) {
            $args[$k] = is_string($v) && is_numeric($v) ? $v + 0 : $v;
        }
        $timeout = $r->queryParam('timeout');
        try {
            $result = Cpu::run($job, $args, is_string($timeout) && is_numeric($timeout) ? (float) $timeout : null);

            return Response::json(['result' => $result, 'http_pid' => getmypid()]);
        } catch (CpuException $e) {
            return Response::json(['error' => $e::class, 'message' => $e->getMessage(), 'remote' => $e instanceof CpuJobException ? $e->remoteClass : null], 503);
        }
    });
}

$app->configureContainer(static function ($worker): void {
    $worker->container->singleton(UsersController::class, static fn(): UsersController => new UsersController());
});

$app->middleware(new TimingMiddleware());
$app->middleware(static function (Request $r, Next $next): Response {
    $response = $next($r);

    return $response->header('X-Order', ($response->getHeader('X-Order') ?? '') . 'global2;');
});

$app->get('/', static fn(): array => ['hello' => 'world']);
$app->get('/text', static fn(): string => 'plain text');
$app->get('/empty', static fn() => null);
$app->get('/slow', static function (Request $r): Response {
    $ms = $r->queryParam('ms');
    Async::sleep((is_string($ms) && ctype_digit($ms) ? (int) $ms : 200) / 1000);

    return Response::json(['slept' => true]);
});
$app->get('/stream', static fn(): Response => Response::stream(static function (Stream $w): void {
    for ($i = 0; $i < 3; $i++) {
        $w->write("event: tick\ndata: {$i}\n\n");
        Async::sleep(1e-3 * 50);
    }
}, 200, 'text/event-stream'));
$app->get('/chunks', static fn(): Response => Response::chunks((static function (): Generator {
    yield 'a';
    yield 'b';
    yield 'c';
})(), 200, 'text/plain'));
$app->get('/files/{path:.+}', static fn(Request $r): array => ['path' => $r->route('path')]);
$app->get('/boom', static function (): never {
    throw new RuntimeException('internal secret');
});
$app->get('/fanout', static function (Request $r): Response {
    $start = hrtime(true);
    $results = Async::all([
        'a' => static function (): string {
            Async::sleep(1e-3 * 200);

            return 'A';
        },
        'b' => static function (): string {
            Async::sleep(1e-3 * 200);

            return 'B';
        },
        'c' => static function (): string {
            Async::sleep(1e-3 * 200);

            return ContextStorage::require()->id;
        },
    ], timeout: 2.0);

    return Response::json(['results' => $results, 'elapsed_ms' => (int) ((hrtime(true) - $start) / 1e6), 'request_id' => $r->id(), 'coroutines' => Coroutine::stats()['coroutine_num'] ?? null]);
});
$app->get('/fanout-timeout', static function (): Response {
    try {
        Async::all(['slow' => static function (): void {
            Async::sleep(1e-3 * 5000);
        }], timeout: 0.2);
    } catch (TimeoutException) {
        return Response::json(['error' => ['status' => 504, 'message' => 'upstream timeout']], 504);
    }

    return Response::json(['unexpected' => true]);
});

$app->get('/proxy', static function (Request $r): Response {
    $upstream = $r->queryParam('upstream');
    $ms = $r->queryParam('ms');
    $url = 'http://127.0.0.1:' . (is_string($upstream) && ctype_digit($upstream) ? $upstream : '18600') . '/slow?ms=' . (is_string($ms) && ctype_digit($ms) ? $ms : '300');
    $response = Http::timeout(5)->get($url);

    return Response::json(['upstream_status' => $response->status(), 'upstream' => $response->json(), 'request_id' => $r->id()]);
});

$app->get('/cache/{key}', static function (Request $r): array {
    $key = is_string($r->route('key')) ? $r->route('key') : '';
    $hits = Cache::increment('hits:' . $key);
    $value = Cache::remember('value:' . $key, 60, static fn(): array => ['computed_for' => $key, 'by' => $r->id()]);

    return ['hits' => $hits, 'value' => $value, 'redis_pool' => RedisFacade::client()->stats()];
});

$app->get('/db/ping', static fn(): array => ['ok' => DB::selectOne('select 1 as ok')['ok'] ?? null, 'pool' => DB::connection()->stats()->toArray()]);
$app->post('/db/users', static function (Request $r): Response {
    $data = $r->jsonObject();
    $email = is_string($data['email'] ?? null) ? $data['email'] : '';
    $name = is_string($data['name'] ?? null) ? $data['name'] : '';
    $id = DB::transaction(static function () use ($email, $name): int {
        $id = DB::table('omni_http_users')->insertGetId(['email' => $email, 'name' => $name]);
        DB::table('omni_http_users')->where('id', $id)->update(['name' => $name . '!']);

        return $id;
    });

    return Response::json(['id' => $id], 201);
});
$app->get('/db/users/{id:\d+}', static function (Request $r): Response {
    $row = DB::table('omni_http_users')->find($r->routeInt('id'));

    return $row === null ? Response::json(['error' => 'not found'], 404) : Response::json($row);
});
$app->get('/db/isolation', static function (Request $r): array {
    return DB::transaction(static function () use ($r): array {
        DB::table('omni_http_users')->insert(['email' => $r->id() . '@iso', 'name' => 'iso', 'tag' => $r->id()]);
        $first = DB::selectOne('select connection_id() as cid')['cid'] ?? null;
        Async::sleep(1e-3 * 40);
        $own = DB::table('omni_http_users')->where('tag', $r->id())->count();
        $second = DB::selectOne('select connection_id() as cid')['cid'] ?? null;

        return ['request_id' => $r->id(), 'own_rows' => $own, 'pinned' => $first === $second, 'cid' => $first];
    });
});
$app->get('/teapot', static function (): never {
    throw new HttpException(418, "I'm a teapot", ['X-Brew' => 'no']);
});

$app->group('/api', static function (Router $r): void {
    $r->get('/users/{id:\d+}', [UsersController::class, 'show'])->name('users.show');
    $r->post('/users', UsersController::class . '::store');
    $r->group('/admin', static function (Router $r): void {
        $r->delete('/users/{id:\d+}', static fn(Request $req): Response => Response::noContent());
    }, [static fn(Request $r, Next $next): Response => $r->attribute('auth.user') === 'admin' ? $next($r) : Response::json(['error' => ['status' => 403, 'message' => 'admin only']], 403)]);
}, [static function (Request $r, Next $next): Response {
    $token = $r->bearerToken();
    if ($token === null) {
        return Response::json(['error' => ['status' => 401, 'message' => 'missing token']], 401)->header('WWW-Authenticate', 'Bearer');
    }
    $r->setAttribute('auth.user', $token === 'admin-token' ? 'admin' : 'user:' . $token);

    $response = $next($r);

    return $response->header('X-Order', ($response->getHeader('X-Order') ?? '') . 'api;');
}]);

$app->run();
