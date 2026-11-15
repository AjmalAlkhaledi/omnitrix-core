<?php

declare(strict_types=1);

/*
 * Row and query cache benchmark.
 *
 *   php tools/bench/db_cache_bench.php --rows=10000,100000,1000000 --concurrency=16 --seconds=10
 *
 * For every table size it measures, with `--concurrency` coroutines over pooled connections:
 *
 *   1. baseline   — lookups by key against the database (no cache)
 *   2. row cache  — the same lookups with the row cache
 *   3. query cache — repeated `where('user_id', ?)` queries with the query cache
 *   4. writes     — updates by key and one bulk UPDATE with the cache (invalidation + locks)
 *
 * and reports throughput, latency percentiles, Redis memory, cache hit/miss/bypass/error rates
 * and the bulk UPDATE duration. Run it on hardware representative of production, with the
 * database and Redis on their own machines; run several processes in parallel to model several
 * workers. Connection settings come from the OMNITRIX_TEST_* variables (see .env.example).
 */

use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Runtime;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$options = getopt('', ['rows::', 'concurrency::', 'seconds::', 'database::']);
$sizes = array_map('intval', explode(',', is_string($options['rows'] ?? null) ? $options['rows'] : '10000,100000'));
$concurrency = max(1, (int) (is_string($options['concurrency'] ?? null) ? $options['concurrency'] : 16));
$seconds = max(1.0, (float) (is_string($options['seconds'] ?? null) ? $options['seconds'] : 5));
$database = is_string($options['database'] ?? null) ? $options['database'] : 'omnitrix_cache_bench';
$env = static fn(string $name, string $default): string => is_string(getenv($name)) && getenv($name) !== '' ? (string) getenv($name) : $default;

Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
Coroutine::set(['log_level' => OPENSWOOLE_LOG_ERROR]);
Coroutine::run(static function () use ($sizes, $concurrency, $seconds, $database, $env): void {
    $host = $env('OMNITRIX_TEST_DB_HOST', '127.0.0.1');
    $port = (int) $env('OMNITRIX_TEST_DB_PORT', '33306');
    $user = $env('OMNITRIX_TEST_DB_USER', 'root');
    $password = $env('OMNITRIX_TEST_DB_PASS', '');
    $root = new PDO("mysql:host={$host};port={$port}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $root->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $redisConfig = ['host' => $env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_REDIS_PORT', '63790'), 'prefix' => 'bench:', 'pool' => ['max' => $concurrency + 4]];
    $raw = new Redis();
    $raw->connect($redisConfig['host'], $redisConfig['port']);

    $connect = static function (bool $cached) use ($host, $port, $user, $password, $database, $concurrency, $redisConfig): array {
        $redis = new RedisClient(RedisConfig::fromArray($redisConfig));
        $section = ['driver' => 'mariadb', 'host' => $host, 'port' => $port, 'database' => $database, 'username' => $user, 'password' => $password, 'pool' => ['min' => 0, 'max' => $concurrency + 4]];
        if ($cached) {
            $section['cache'] = ['tables' => ['bench_users'], 'queries' => ['bench_posts' => ['user_id']], 'ttl' => 600];
        }
        $manager = new DatabaseManager($section, null, $redis);

        return [$manager, $redis, $manager->connection()];
    };

    printf("%-9s %-12s %12s %10s %10s %10s %8s %8s %8s %12s\n", 'rows', 'scenario', 'ops/s', 'p50 ms', 'p99 ms', 'redis MB', 'hit %', 'miss %', 'bypass %', 'bulk upd s');
    foreach ($sizes as $rows) {
        seed($pdo, $rows);
        $raw->flushDB();
        $users = max(1, intdiv($rows, 10));

        [$manager, $redis, $db] = $connect(false);
        report($rows, 'baseline', run($concurrency, $seconds, static fn() => $db->table('bench_users')->find(random_int(1, $rows))), $raw, $db, null);
        $manager->close();
        $redis->close();

        [$manager, $redis, $db] = $connect(true);
        report($rows, 'row cache', run($concurrency, $seconds, static fn() => $db->table('bench_users')->find(random_int(1, $rows))), $raw, $db, null);
        report($rows, 'query', run($concurrency, $seconds, static fn() => $db->table('bench_posts')->where('user_id', random_int(1, $users))->cache(600)->get()), $raw, $db, null);
        $writes = run($concurrency, $seconds, static fn() => $db->table('bench_users')->where('id', random_int(1, $rows))->increment('score'));
        $started = hrtime(true);
        $db->table('bench_users')->where('score', '>', 0)->update(['score' => 0]);
        report($rows, 'writes', $writes, $raw, $db, (hrtime(true) - $started) / 1e9);
        $manager->close();
        $redis->close();
    }
});

/** @return array{ops: int, seconds: float, latencies: list<float>} */
function run(int $concurrency, float $seconds, Closure $operation): array
{
    $deadline = microtime(true) + $seconds;
    $done = new Channel($concurrency);
    $started = microtime(true);
    for ($i = 0; $i < $concurrency; $i++) {
        Coroutine::create(static function () use ($operation, $deadline, $done): void {
            $latencies = [];
            while (microtime(true) < $deadline) {
                $t = hrtime(true);
                $operation();
                $latencies[] = (hrtime(true) - $t) / 1e6;
            }
            $done->push($latencies);
        });
    }
    $all = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $all = array_merge($all, (array) $done->pop());
    }
    sort($all);

    return ['ops' => count($all), 'seconds' => microtime(true) - $started, 'latencies' => $all];
}

/** @param array{ops: int, seconds: float, latencies: list<float>} $result */
function report(int $rows, string $scenario, array $result, Redis $raw, Database $db, ?float $bulk): void
{
    $memory = $raw->info('memory');
    $used = is_array($memory) ? (float) ($memory['used_memory'] ?? 0) / 1048576 : 0.0;
    $stats = $db->cacheStats();
    $reads = max(1, $stats['hits'] + $stats['misses'] + $stats['bypassed']);
    $p = static fn(float $q): float => $result['latencies'] === [] ? 0.0 : $result['latencies'][(int) floor($q * (count($result['latencies']) - 1))];
    printf(
        "%-9d %-12s %12.0f %10.3f %10.3f %10.1f %8.1f %8.1f %8.1f %12s\n",
        $rows, $scenario, $result['ops'] / max(0.001, $result['seconds']), $p(0.5), $p(0.99), $used,
        100 * $stats['hits'] / $reads, 100 * $stats['misses'] / $reads, 100 * $stats['bypassed'] / $reads,
        $bulk === null ? '-' : sprintf('%.3f', $bulk) . ($stats['errors'] > 0 ? " ({$stats['errors']} errors)" : ''),
    );
}

function seed(PDO $pdo, int $rows): void
{
    $pdo->exec('DROP TABLE IF EXISTS bench_posts, bench_users');
    $pdo->exec('CREATE TABLE bench_users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, score INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE bench_posts (id INT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(50) NOT NULL, KEY (user_id)) ENGINE=InnoDB');
    for ($start = 1; $start <= $rows; $start += 5000) {
        $values = [];
        $posts = [];
        for ($id = $start; $id < min($rows + 1, $start + 5000); $id++) {
            $values[] = "({$id}, 'user {$id}', 0)";
            $posts[] = "({$id}, " . (($id % max(1, intdiv($rows, 10))) + 1) . ", 'post {$id}')";
        }
        $pdo->exec('INSERT INTO bench_users VALUES ' . implode(',', $values));
        $pdo->exec('INSERT INTO bench_posts VALUES ' . implode(',', $posts));
    }
}
