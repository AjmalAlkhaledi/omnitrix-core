<?php

declare(strict_types=1);

/*
 * Omnitrix implementation of the two benchmark endpoints (same SQL and upstream as the Go
 * reference). Env: BENCH_PORT, BENCH_WORKERS, BENCH_POOL (per worker), BENCH_UPSTREAM,
 * OMNITRIX_TEST_DB_* (defaults match bin/services).
 */

use Omnitrix\Application;
use Omnitrix\Config\Env;
use Omnitrix\Facades\DB;
use Omnitrix\Facades\Http;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Psr\Log\NullLogger;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$workers = Env::int('BENCH_WORKERS', 2);
$pool = Env::int('BENCH_POOL', 32);
$upstream = Env::get('BENCH_UPSTREAM', 'http://127.0.0.1:18700') ?? 'http://127.0.0.1:18700';

$app = Application::create([
    'server' => ['host' => '127.0.0.1', 'port' => Env::int('BENCH_PORT', 18720), 'workers' => $workers, 'log_level' => 'error', 'log_file' => dirname(__DIR__, 2) . '/var/log/bench-php.log', 'backlog' => 4096, 'max_coroutine' => 100000],
    'http' => ['request_timeout' => 10, 'request_id_header' => null],
    'database' => [
        'driver' => 'mariadb', 'host' => Env::get('OMNITRIX_TEST_DB_HOST', '127.0.0.1') ?? '127.0.0.1', 'port' => Env::int('OMNITRIX_TEST_DB_PORT', 33306),
        'database' => Env::get('OMNITRIX_TEST_DB_NAME', 'omnitrix_test') ?? 'omnitrix_test', 'username' => Env::get('OMNITRIX_TEST_DB_USER', 'root') ?? 'root', 'password' => Env::get('OMNITRIX_TEST_DB_PASS', '') ?? '',
        'pool' => ['min' => $pool, 'max' => $pool, 'acquire_timeout' => 5.0, 'validation_interval' => 30.0],
        'statement_timeout' => 0,
    ],
    'http_client' => ['timeout' => 5.0, 'pool' => ['max_per_host' => $pool]],
], new NullLogger());
if (Env::bool('BENCH_METRICS', false)) {
    $app->enableMetrics('/metrics'); // used by the soak run to sample pools, coroutines and event-loop lag
}

// Benchmark A: parse JSON → SELECT → UPDATE → JSON
$app->post('/a', static function (Request $request): Response {
    $in = $request->jsonObject();
    $id = is_int($in['id'] ?? null) ? $in['id'] : 0;
    $amount = is_int($in['amount'] ?? null) ? $in['amount'] : 0;
    if ($id <= 0) {
        return Response::json(['error' => 'bad request'], 400);
    }
    $user = DB::selectOne('SELECT id, name, balance, visits FROM bench_users WHERE id = ?', [$id]);
    if ($user === null) {
        return Response::json(['error' => 'not found'], 404);
    }
    DB::execute('UPDATE bench_users SET visits = visits + 1, balance = balance + ? WHERE id = ?', [$amount, $id]);
    $user['balance'] = (is_int($user['balance']) ? $user['balance'] : 0) + $amount;
    $user['visits'] = (is_int($user['visits']) ? $user['visits'] : 0) + 1;

    return Response::json($user);
});

// Benchmark B: SELECT → external HTTP → logic → UPDATE → JSON
$app->get('/b', static function (Request $request) use ($upstream): Response {
    $raw = $request->queryParam('id');
    $id = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
    if ($id <= 0) {
        return Response::json(['error' => 'bad request'], 400);
    }
    $user = DB::selectOne('SELECT id, name, balance, visits FROM bench_users WHERE id = ?', [$id]);
    if ($user === null) {
        return Response::json(['error' => 'not found'], 404);
    }
    $quote = Http::get($upstream . '/quote', ['id' => $id]);
    if (!$quote->ok()) {
        return Response::json(['error' => 'upstream failed'], 502);
    }
    $rate = $quote->jsonObject()['rate'] ?? 1.0;
    $user['score'] = (is_int($user['balance']) ? $user['balance'] : 0) * (is_float($rate) || is_int($rate) ? $rate : 1.0);
    DB::execute('UPDATE bench_users SET score = ? WHERE id = ?', [$user['score'], $id]);

    return Response::json($user);
});

$app->run();
