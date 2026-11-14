<?php

declare(strict_types=1);

/*
 * Production middleware fixture (auth, authorization, rate limiting, security
 * headers, access log, validation, metrics, health with database and Redis checks).
 * Env: OMNITRIX_FIXTURE_PORT, OMNITRIX_TEST_DB_*, OMNITRIX_TEST_REDIS_*.
 */

use Omnitrix\Application;
use Omnitrix\Config\Env;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Observability\AccessLog;
use Omnitrix\RateLimit\RateLimit;
use Omnitrix\RateLimit\TableRateLimiter;
use Omnitrix\Routing\Router;
use Omnitrix\Security\Authorize;
use Omnitrix\Security\BearerTokenAuth;
use Omnitrix\Security\Identity;
use Omnitrix\Security\SecurityHeaders;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = Application::create([
    'server' => ['host' => '127.0.0.1', 'port' => Env::int('OMNITRIX_FIXTURE_PORT', 18390), 'workers' => 2, 'log_level' => 'error', 'log_file' => dirname(__DIR__, 3) . '/var/log/fixture-production.log', 'backlog' => 1024],
    'http' => ['request_timeout' => 5],
    'database' => [
        'driver' => 'mariadb', 'host' => Env::get('OMNITRIX_TEST_DB_HOST', '127.0.0.1') ?? '127.0.0.1', 'port' => Env::int('OMNITRIX_TEST_DB_PORT', 33306),
        'database' => Env::get('OMNITRIX_TEST_DB_NAME', 'omnitrix_test') ?? 'omnitrix_test', 'username' => Env::get('OMNITRIX_TEST_DB_USER', 'root') ?? 'root', 'password' => Env::get('OMNITRIX_TEST_DB_PASS', '') ?? '',
        'pool' => ['min' => 1, 'max' => 4],
    ],
    'redis' => ['host' => Env::get('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1') ?? '127.0.0.1', 'port' => Env::int('OMNITRIX_TEST_REDIS_PORT', 63790), 'prefix' => 'omnitrix-prod-test:'],
]);

$limiter = new TableRateLimiter(); // shared memory: created before the server forks workers
$auth = new BearerTokenAuth(static function (string $token): ?Identity {
    Sleep::milliseconds(5); // simulates a token lookup that suspends (DB/introspection)
    if (preg_match('/^user-(\d+)$/', $token, $m) === 1) {
        return new Identity($m[1], ['profile.read']);
    }

    return $token === 'admin-secret-token' ? new Identity('admin', ['*']) : null;
});

$app->middleware(new SecurityHeaders(), new AccessLog($app->logger(), ['/health/live', '/health/ready', '/metrics']));
$app->enableMetrics('/metrics');
$app->health('/health');

$app->group('/api', static function (Router $r) use ($limiter): void {
    $r->get('/me', static function (Request $request): array {
        $identity = BearerTokenAuth::identity($request);
        Sleep::milliseconds(20); // other requests interleave here
        $again = BearerTokenAuth::identity($request);

        return ['id' => $identity?->id, 'stable' => $identity === $again, 'context_id' => $request->context()->get(BearerTokenAuth::ATTRIBUTE) === $identity];
    });
    $r->get('/admin', static fn(): array => ['admin' => true])->middleware(Authorize::ability('admin.read'));
    $r->post('/users', static fn(Request $request): Response => Response::json(['data' => $request->validate(['name' => 'required|string|max:50', 'email' => 'required|email'])], 201));
    $r->get('/limited', static fn(): array => ['ok' => true])->middleware(new RateLimit($limiter, 5, 60, static fn(Request $q): string => 'shared-key'));
}, [$auth]);

$app->run();
