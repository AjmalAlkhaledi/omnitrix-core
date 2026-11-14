<?php

declare(strict_types=1);

/*
 * HTTP server fixture: ServerRuntime + HttpServerAdapter with a hand-written dispatcher
 * (no router yet). Env: OMNITRIX_FIXTURE_PORT, OMNITRIX_FIXTURE_MARKERS, OMNITRIX_FIXTURE_TIMEOUT.
 */

use Omnitrix\Core\Http\Server\HttpServerAdapter;
use Omnitrix\Core\Http\Server\HttpServerConfig;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Http\Server\RequestRejectedException;
use Omnitrix\Core\Http\Server\ResponseWriter;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Lifecycle\ServerRuntime;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use OpenSwoole\Coroutine;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};
$markers = $env('OMNITRIX_FIXTURE_MARKERS', '/dev/null');
$mark = static function (string $event, array $data = []) use ($markers): void {
    file_put_contents($markers, json_encode(['event' => $event, 'pid' => getmypid()] + $data) . "\n", FILE_APPEND | LOCK_EX);
};
$filePath = sys_get_temp_dir() . '/omnitrix-http-fixture-' . getmypid() . '.bin';

$app = ServerRuntime::create([
    'server' => [
        'host' => '127.0.0.1',
        'port' => (int) $env('OMNITRIX_FIXTURE_PORT', '18300'),
        'workers' => 2,
        'max_wait_time' => 5,
        'log_level' => 'error',
        'log_file' => dirname(__DIR__, 3) . '/var/log/fixture-http.log',
        'backlog' => 1024,
    ],
]);
$http = HttpServerConfig::fromArray([
    'max_body_size' => 64 * 1024,
    'request_timeout' => (float) $env('OMNITRIX_FIXTURE_TIMEOUT', '0.5'),
], $app->runtimeConfig()->packageMaxLength);

$app->onWorkerStart(static function (WorkerContext $worker) use ($filePath): void {
    file_put_contents($filePath, str_repeat('F', 100_000));
    $worker->resources->register('fixture.file', static function () use ($filePath): void {
        @unlink($filePath);
    });
});

$app->handle(new HttpServerAdapter($http, static function (IncomingRequest $req, ResponseWriter $res, RequestContext $ctx) use ($app, $mark, $filePath): void {
    $int = static fn(string $name, int $default): int => (int) (is_numeric($req->queryParam($name)) ? $req->queryParam($name) : $default);

    switch ($req->path()) {
        case '/echo':
            $res->cookie('seen', '1');
            $res->json([
                'method' => $req->method(), 'path' => $req->path(), 'query' => $req->query(), 'protocol' => $req->protocolVersion(),
                'headers' => ['x-probe' => $req->header('X-Probe'), 'host' => $req->header('host'), 'user-agent' => $req->header('user-agent')],
                'cookies' => $req->cookies(), 'content_type' => $req->contentType(), 'body_length' => $req->bodyLength(),
                'remote' => $req->remoteAddress(), 'request_id' => $ctx->id, 'worker_pid' => getmypid(), 'cid' => Coroutine::getCid(),
            ]);
            return;

        case '/json':
            $res->json(['received' => $req->jsonObject(), 'is_json' => $req->isJson()], 201);
            return;

        case '/status':
            $res->text('status ' . $int('code', 200), $int('code', 200));
            return;

        case '/stream':
            $n = $int('n', 5);
            $delay = $int('delay', 50);
            $res->status(200)->header('Content-Type', 'text/plain');
            $results = [];
            for ($i = 0; $i < $n; $i++) {
                $results[] = $res->write("chunk{$i}\n");
                Sleep::milliseconds($delay);
            }
            $results[] = $res->end("end\n");
            $mark('stream.done', ['writes' => $results, 'client_gone' => $res->clientGone(), 'request_id' => $ctx->id]);
            return;

        case '/slow':
            Sleep::milliseconds($int('ms', 500));
            $res->json(['slept_ms' => $int('ms', 500), 'pid' => getmypid()]);
            return;

        case '/large':
            $res->header('Content-Type', 'application/octet-stream');
            $res->end(str_repeat('L', $int('bytes', 5_000_000)));
            return;

        case '/head':
            $res->text('head body');
            return;

        case '/file':
            $res->header('Content-Type', 'application/octet-stream');
            $res->sendFile($filePath);
            return;

        case '/redirect':
            $res->redirect('/echo', 307);
            return;

        case '/throw-http':
            throw new RequestRejectedException(422, 'Validation failed', ['X-Error-Kind' => 'validation'], ['field' => 'email']);

        case '/throw':
            throw new RuntimeException('secret internal detail');

        case '/noend':
            return;

        case '/stats':
            $stats = $app->server()?->stats() ?? [];
            $res->json(['connections_accepted' => $stats['connections_accepted'] ?? null, 'connections_active' => $stats['connections_active'] ?? null, 'coroutine_num' => Coroutine::stats()['coroutine_num'] ?? null, 'requests_total' => $stats['requests_total'] ?? null]);
            return;

        default:
            $res->json(['error' => 'no such fixture route'], 404);
    }
}, $app->exceptionHandler()));

$app->run();
