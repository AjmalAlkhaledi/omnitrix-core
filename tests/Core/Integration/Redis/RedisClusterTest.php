<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Redis;

use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Redis\RedisPool;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use Omnitrix\RateLimit\RedisRateLimiter;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use RedisCluster;
use RuntimeException;
use Throwable;

use function array_column;
use function array_map;
use function array_merge;
use function count;
use function is_array;
use function is_dir;
use function is_int;
use function is_string;
use function mkdir;
use function php_uname;
use function random_int;
use function str_contains;
use function sys_get_temp_dir;
use function uniqid;
use function usleep;

/**
 * Redis Cluster (three masters started for the test): client commands across slots, the queue,
 * the rate limiter and the row/query cache, including a node restarting from a stale snapshot.
 */
#[CoversClass(RedisClient::class)]
#[CoversClass(RedisPool::class)]
#[CoversClass(RedisConfig::class)]
#[CoversClass(RedisQueue::class)]
#[CoversClass(RowCache::class)]
final class RedisClusterTest extends TestCase
{
    private const string DB = 'omnitrix_cluster_test';

    /** @var list<int> */
    private static array $ports = [];

    /** @var array<int, ProcessRunner> port => node */
    private static array $nodes = [];

    private static string $dir = '';

    /** @var list<RedisClient> */
    private array $clients = [];

    /** @var list<DatabaseManager> */
    private array $managers = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $base = 27000 + random_int(0, 300) * 3;
        self::$ports = [$base, $base + 1, $base + 2];
        self::$dir = sys_get_temp_dir() . '/omnitrix-cluster-' . uniqid();
        foreach (self::$ports as $port) {
            self::startNode($port);
        }
        $create = ProcessRunner::command(array_merge(['redis-cli', '--cluster', 'create'], array_map(static fn(int $p): string => "127.0.0.1:{$p}", self::$ports), ['--cluster-replicas', '0', '--cluster-yes']));
        if ($create->wait(60.0) !== 0) {
            throw new RuntimeException('redis-cli --cluster create failed: ' . $create->stdout() . $create->stderr());
        }
        self::waitForCluster();
    }

    /** Every node reports cluster_state:ok (the slot map has reached all of them). */
    private static function waitForCluster(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $ok = true;
            foreach (self::$ports as $port) {
                try {
                    $info = self::node($port)->rawcommand('CLUSTER', 'INFO');
                    $ok = $ok && is_string($info) && str_contains($info, 'cluster_state:ok');
                } catch (Throwable) {
                    $ok = false;
                }
            }
            if ($ok) {
                return;
            }
            usleep(100_000);
        }
        throw new RuntimeException('the test cluster did not reach cluster_state:ok on every node');
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$nodes as $node) {
            $node->stop(5.0);
        }
        self::$nodes = [];
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->close();
        }
        foreach ($this->clients as $client) {
            $client->close();
        }
        parent::tearDown();
    }

    public function testCommandsAcrossSlotsPipelinesAndTransactions(): void
    {
        $redis = $this->client();
        self::assertTrue($redis->ping());
        $keys = [];
        for ($i = 0; $i < 20; $i++) {
            $keys[] = "k{$i}";
            $redis->set("k{$i}", "v{$i}");
        }
        self::assertGreaterThan(1, $this->nodesHolding($keys), 'the keys are spread over several nodes');
        self::assertSame('v7', $redis->get('k7'));
        self::assertSame(['v0', 'v19', null], $redis->mget(['k0', 'k19', 'missing']));
        self::assertSame(20, $redis->delete(...$keys));

        $replies = $redis->pipeline(static function (Redis|RedisCluster $r): void {
            $r->set('{p}a', '1');
            $r->incr('{p}a');
            $r->get('{p}a');
        });
        self::assertSame([true, 2, '2'], $replies);
        foreach (['raw-a', 'raw-b', 'raw-c', 'raw-d', 'raw-e'] as $key) {
            self::assertTrue($redis->command('SET', [$key, 'raw']), "raw command for {$key} reaches its node");
            self::assertSame('raw', $redis->command('GET', [$key]), 'raw arguments are not prefixed');
        }
    }

    public function testQueueAndRateLimiterRunOnTheCluster(): void
    {
        $queue = new RedisQueue($this->client());
        $name = 'jobs-' . uniqid();
        $queue->push(Job::create('send', ['n' => 1], $name));
        $queue->push(Job::create('later', ['n' => 2], $name), 0.2);
        $reserved = $queue->reserve($name, 'c1', 1);
        self::assertNotNull($reserved);
        self::assertSame('send', $reserved[0]->name);
        self::assertSame(1, $queue->stats($name)['processing']);
        self::assertSame(1, $queue->recover($name), 'a consumer without heartbeat gives its job back');
        $again = $queue->reserve($name, 'c2', 1);
        self::assertNotNull($again);
        $queue->ack($name, 'c2', $again[1]);
        usleep(300_000);
        self::assertSame(1, $queue->promoteDue($name));
        self::assertSame(['ready' => 1, 'delayed' => 0, 'processing' => 0, 'dead' => 0], $queue->stats($name));

        $limiter = new RedisRateLimiter($this->client(), 'rl:');
        self::assertTrue($limiter->hit('ip-1', 2, 60)->allowed);
        self::assertTrue($limiter->hit('ip-1', 2, 60)->allowed);
        self::assertFalse($limiter->hit('ip-1', 2, 60)->allowed);
    }

    public function testRowAndQueryCacheWithCascadesAcrossNodes(): void
    {
        $behind = $this->database();
        $db = $this->cachedDatabase($this->client('cache-' . uniqid() . ':'));
        self::assertSame('Ann', $db->table('users')->find(1)['name'] ?? null);
        self::assertSame(['First', 'Second'], array_column($db->table('posts')->where('user_id', 1)->cache(60)->get(), 'title'));
        self::assertSame('First', $db->table('posts')->find(10)['title'] ?? null);
        $behind->exec("UPDATE users SET name = 'behind'");
        $behind->exec("UPDATE posts SET title = 'behind'");
        self::assertSame('Ann', $db->table('users')->find(1)['name'] ?? null, 'served from the cache');
        self::assertSame('First', $db->table('posts')->find(10)['title'] ?? null);

        $db->table('users')->where('id', 1)->delete(); // cascades into posts: marks keys of two tables (two slots)

        self::assertNull($db->table('users')->find(1));
        self::assertNull($db->table('posts')->find(10), 'the cascaded row is not served');
        self::assertSame([], $db->table('posts')->where('user_id', 1)->cache(60)->get());

        $db->table('audit')->insert(['note' => 'x']); // trigger writes counters
        self::assertSame(1, self::int($db->table('counters')->find(1)['n'] ?? null));
        self::assertGreaterThan(0, $db->cacheStats()['hits']);
    }

    public function testANodeRestartingFromAStaleSnapshotDoesNotServeStaleRows(): void
    {
        $behind = $this->database();
        $prefix = 'fence-' . uniqid() . ':';
        $db = $this->cachedDatabase($this->client($prefix));
        self::assertSame('Ann', $db->table('users')->find(1)['name'] ?? null);
        $port = $this->portOf($prefix . 'dbc:{default:users}:g');
        self::node($port)->save(); // snapshot with Ann cached

        $db->table('users')->where('id', 1)->update(['name' => 'Ann B.']);
        self::assertSame('Ann B.', $db->table('users')->find(1)['name'] ?? null);

        self::restartNode($port); // loads the snapshot: the old entry and its version are back
        $fresh = $this->cachedDatabase($this->client($prefix));
        self::assertSame('Ann B.', $fresh->table('users')->find(1)['name'] ?? null, 'the new run_id retires the table');
        $behind->exec("UPDATE users SET name = 'behind' WHERE id = 2");
        self::assertSame('behind', $fresh->table('users')->find(2)['name'] ?? null);
    }

    private function client(string $prefix = 'cluster-test:'): RedisClient
    {
        $client = new RedisClient(RedisConfig::fromArray([
            'cluster' => array_map(static fn(int $p): string => "127.0.0.1:{$p}", self::$ports),
            'prefix' => $prefix,
            'pool' => ['max' => 4],
        ]));
        $this->clients[] = $client;

        return $client;
    }

    private function database(): PDO
    {
        $root = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort(), Services::dbUser(), Services::dbPassword());
        $root->exec('DROP DATABASE IF EXISTS `' . self::DB . '`');
        $root->exec('CREATE DATABASE `' . self::DB . '`');
        $pdo = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . self::DB, Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE posts (id INT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(50) NOT NULL, CONSTRAINT posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE counters (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE audit (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $pdo->exec('CREATE TRIGGER audit_count AFTER INSERT ON audit FOR EACH ROW UPDATE counters SET n = n + 1 WHERE id = 1');
        $pdo->exec("INSERT INTO users VALUES (1, 'Ann'), (2, 'Bob')");
        $pdo->exec("INSERT INTO posts VALUES (10, 1, 'First'), (11, 1, 'Second'), (20, 2, 'Bob')");
        $pdo->exec('INSERT INTO counters VALUES (1, 0)');

        return $pdo;
    }

    private function cachedDatabase(RedisClient $redis): \Omnitrix\Core\Database\Database
    {
        $manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 4],
            'cache' => ['tables' => ['users', 'posts', 'counters'], 'queries' => ['posts' => ['user_id']], 'ttl' => 60],
        ], null, $redis);
        $this->managers[] = $manager;

        return $manager->connection();
    }

    /** @param list<string> $keys */
    private function nodesHolding(array $keys): int
    {
        $ports = [];
        foreach ($keys as $key) {
            $ports[$this->portOf('cluster-test:' . $key)] = true;
        }

        return count($ports);
    }

    /** Port of the master serving the slot of a (fully prefixed) key. */
    private function portOf(string $key): int
    {
        $node = self::node(self::$ports[0]);
        $slot = $node->rawcommand('CLUSTER', 'KEYSLOT', $key);
        $ranges = $node->rawcommand('CLUSTER', 'SLOTS');
        foreach (is_array($ranges) ? $ranges : [] as $range) {
            if (is_array($range) && is_int($slot) && $slot >= $range[0] && $slot <= $range[1] && is_array($range[2]) && is_int($range[2][1] ?? null)) {
                return $range[2][1];
            }
        }
        throw new RuntimeException("no node serves {$key}");
    }

    private static function node(int $port): Redis
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', $port, 2.0);

        return $redis;
    }

    private static function startNode(int $port): void
    {
        $dir = self::$dir . '/' . $port;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $args = ['--port', (string) $port, '--bind', '127.0.0.1', '--dir', $dir, '--cluster-enabled', 'yes', '--cluster-config-file', 'nodes.conf',
            '--cluster-node-timeout', '2000', '--save', '', '--appendonly', 'no', '--dbfilename', 'dump.rdb'];
        if (php_uname('m') === 'aarch64') {
            $args = array_merge($args, ['--ignore-warnings', 'ARM64-COW-BUG']);
        }
        self::$nodes[$port] = new ProcessRunner('redis-server', $args, [], null);
        self::$nodes[$port]->waitForPort('127.0.0.1', $port, 10.0);
    }

    private static function restartNode(int $port): void
    {
        try {
            self::node($port)->rawcommand('SHUTDOWN', 'NOSAVE');
        } catch (Throwable) {
            // the node closes the connection while shutting down
        }
        self::$nodes[$port]->stop(5.0);
        self::startNode($port);
        self::waitForCluster();
    }
}
