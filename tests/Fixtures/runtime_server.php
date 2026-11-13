<?php

declare(strict_types=1);

/*
 * Runtime Core fixture server (no routing layer): exercises Application lifecycle, worker
 * hooks, request context, exception containment, resources and graceful shutdown.
 * Env: OMNITRIX_FIXTURE_PORT, OMNITRIX_FIXTURE_MODE (simple|process), OMNITRIX_FIXTURE_WORKERS,
 *      OMNITRIX_FIXTURE_MARKERS (file receiving one line per lifecycle event).
 */

use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Coroutines;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Lifecycle\ServerRuntime;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};
$port = (int) $env('OMNITRIX_FIXTURE_PORT', '18200');
$markers = $env('OMNITRIX_FIXTURE_MARKERS', '/dev/null');
$mark = static function (string $event, array $data = []) use ($markers): void {
    file_put_contents($markers, json_encode(['event' => $event, 'pid' => getmypid()] + $data) . "\n", FILE_APPEND | LOCK_EX);
};

$app = ServerRuntime::create([
    'server' => [
        'host' => '127.0.0.1',
        'port' => $port,
        'mode' => $env('OMNITRIX_FIXTURE_MODE', 'simple'),
        'workers' => (int) $env('OMNITRIX_FIXTURE_WORKERS', '2'),
        'max_wait_time' => 5,
        'log_level' => 'error',
        'log_file' => dirname(__DIR__, 2) . '/var/log/fixture-runtime.log',
        'backlog' => 1024,
    ],
]);

$app->onWorkerStart(static function (WorkerContext $worker) use ($mark): void {
    $mark('worker.start', ['worker_id' => $worker->workerId, 'role' => $worker->role->value]);
    $worker->resources->register('fixture.resource', static function () use ($mark, $worker): void {
        $mark('resource.released', ['worker_id' => $worker->workerId]);
    }, 10);
});
$app->onWorkerStop(static function (WorkerContext $worker) use ($mark): void {
    $mark('worker.stop', ['worker_id' => $worker->workerId]);
});

$app->handle(static function (Request $request, Response $response, RequestContext $context) use ($app, $mark): void {
    $server = is_array($request->server) ? $request->server : [];
    $query = is_array($request->get) ? $request->get : [];
    $uri = is_string($server['request_uri'] ?? null) ? $server['request_uri'] : '/';
    $send = static function (array $payload, int $status = 200) use ($response, $context): void {
        $response->status($status);
        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($payload + ['request_id' => $context->id], JSON_THROW_ON_ERROR));
    };

    switch ($uri) {
        case '/info':
            $send(['pid' => getmypid(), 'worker_id' => $app->server()?->getWorkerId(), 'cid' => Coroutine::getCid()]);
            return;

        case '/slow':
            Sleep::for(1.0);
            $send(['slept' => 1.0, 'pid' => getmypid()]);
            return;

        case '/context':
            // Isolation + inheritance: value set here must be visible in a child coroutine and
            // must never leak into a concurrent request (checked by the test via unique values).
            $value = is_string($query['v'] ?? null) ? $query['v'] : '';
            $context->set('v', $value);
            Sleep::for(0.2);
            $seenByChild = null;
            $done = new Coroutine\Channel(1);
            Coroutines::spawn(static function () use (&$seenByChild, $done): void {
                $seenByChild = ContextStorage::current()?->get('v');
                $done->push(true);
            }, $app->exceptionHandler());
            $done->pop();
            $send(['own' => $context->get('v'), 'child' => $seenByChild, 'same_id' => ContextStorage::require()->id === $context->id]);
            return;

        case '/throw':
            throw new RuntimeException('handler failure');

        case '/throw-child':
            Coroutines::spawn(static function (): void {
                throw new RuntimeException('child failure');
            }, $app->exceptionHandler(), ['request_id' => $context->id]);
            Sleep::for(0.05);
            $send(['spawned' => true, 'pid' => getmypid()]);
            return;

        case '/defer':
            $context->defer(static function () use ($mark, $context): void {
                $mark('request.deferred', ['request_id' => $context->id]);
            });
            $send(['deferred' => true]);
            return;

        case '/fatal':
            // Simulates a crash the framework cannot catch (worker process dies).
            $pid = getmypid();
            if ($pid !== false) {
                posix_kill($pid, SIGKILL);
            }

            return;

        default:
            $send(['error' => 'not found'], 404);
    }
});

$app->run();
