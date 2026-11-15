<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Database;

use Omnitrix\Core\Database\Cache\CacheCoordinator;
use Omnitrix\Core\Database\Cache\CacheUnavailableException;
use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use RuntimeException;
use Throwable;

use function array_merge;
use function explode;
use function fclose;
use function fsockopen;
use function is_string;
use function mkdir;
use function php_uname;
use function random_int;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Failure and concurrency scenarios of the row cache: Redis stopping and restarting with data
 * saved before writes it never saw, transactions longer than lock_ttl, rollbacks, deadlock
 * retries, multi-level cascades, several workers and application restarts.
 *
 * Each test uses its own Redis server (with an RDB file) so it can be stopped and restarted.
 */
#[CoversClass(RowCache::class)]
#[CoversClass(CacheCoordinator::class)]
#[CoversClass(TransactionManager::class)]
#[CoversClass(Database::class)]
final class CacheReliabilityTest extends TestCase
{
    private const string DB = 'omnitrix_cache_reliability_test';

    private PDO $behind;

    private int $redisPort = 0;

    private string $redisDir = '';

    private ?ProcessRunner $redisServer = null;

    /** @var list<DatabaseManager> */
    private array $managers = [];

    /** @var list<RedisClient> */
    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $root = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort(), Services::dbUser(), Services::dbPassword());
        $root->exec('DROP DATABASE IF EXISTS `' . self::DB . '`');
        $root->exec('CREATE DATABASE `' . self::DB . '`');
        $this->behind = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . self::DB, Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->behind->exec('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, score INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $this->behind->exec("INSERT INTO users (id, name) VALUES (1, 'Ann'), (2, 'Bob'), (3, 'Cy')");
        $this->behind->exec('CREATE TABLE events (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL) ENGINE=InnoDB');

        $this->redisPort = 26400 + random_int(0, 499);
        $this->redisDir = sys_get_temp_dir() . '/omnitrix-redis-' . uniqid();
        mkdir($this->redisDir);
        $this->startRedis();
    }

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $manager->close();
        }
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->stopRedis(save: false);
        parent::tearDown();
    }

    public function testRedisDownBeforeAWriteRejectsTheWriteInStrictMode(): void
    {
        $db = $this->database();
        self::assertSame('Ann', $this->nameOf($db, 1));
        $this->stopRedis(save: true);

        try {
            $db->table('users')->where('id', 1)->update(['name' => 'not written']);
            self::fail('strict mode must refuse the write');
        } catch (CacheUnavailableException) {
        }
        self::assertSame('Ann', $this->behindName(1), 'the database was not changed');

        $this->startRedis();
        self::assertSame('Ann', $this->nameOf($db, 1));
    }

    public function testRedisRestartedWithDataFromBeforeAWriteServesNothingStale(): void
    {
        $db = $this->database(strict: false);
        self::assertSame('Ann', $this->nameOf($db, 1));
        $this->stopRedis(save: true);                       // the snapshot holds 'Ann'

        $db->table('users')->where('id', 1)->update(['name' => 'Ann (while down)']);
        self::assertSame('Ann (while down)', $this->nameOf($db, 1), 'reads use the database while Redis is down');

        $this->startRedis();                                 // loads the snapshot with the old entry
        $fresh = $this->database(strict: false);             // another worker that never saw the failure
        self::assertSame('Ann (while down)', $this->nameOf($fresh, 1), 'the restarted server is fenced off');
        self::assertSame('Ann (while down)', $this->nameOf($db, 1));
    }

    public function testRedisDyingAfterTheMarkWasSavedServesNothingStale(): void
    {
        $this->dieDuringAWrite(snapshotAfterMark: true);
    }

    public function testRedisDyingAfterTheMarkWasLostServesNothingStale(): void
    {
        $this->dieDuringAWrite(snapshotAfterMark: false);
    }

    public function testTransactionsLongerThanLockTtlKeepTheirMarks(): void
    {
        $db = $this->database(lockTtl: 1);
        self::assertSame('Ann', $this->nameOf($db, 1));
        $cache = new RowCache($this->client(), $db->config->cache, 'default');

        [$results] = self::runConcurrently([
            'writer' => function () use ($db): void {
                $db->transaction(function () use ($db): void {
                    $db->table('users')->where('id', 1)->update(['name' => 'Ann (long tx)']);
                    self::wait(3.0);                         // three times lock_ttl
                });
            },
            'observer' => function () use ($db, $cache): array {
                $seen = [];
                for ($i = 0; $i < 5; $i++) {
                    self::wait(0.5);
                    [$lookup] = $cache->lookup('users', [[1]]);
                    $seen[] = [$lookup->hit, $lookup->fillable];
                    $seen[] = $this->nameOf($db, 1);
                }

                return $seen;
            },
        ]);
        $observed = $results['observer'];
        self::assertIsArray($observed);
        foreach ($observed as $i => $value) {
            if ($i % 2 === 0) {
                self::assertSame([false, false], $value, 'the mark is still pending after ' . (($i / 2 + 1) * 0.5) . ' s');
            } else {
                self::assertSame('Ann', $value, 'readers see the committed value during the transaction');
            }
        }
        self::assertSame('Ann (long tx)', $this->nameOf($db, 1));
    }

    public function testRollbacksDoNotInvalidate(): void
    {
        $db = $this->database();
        self::assertSame('Ann', $this->nameOf($db, 1));
        self::assertSame('Bob', $this->nameOf($db, 2));
        self::assertSame('Cy', $this->nameOf($db, 3));
        $this->behind->exec("UPDATE users SET name = CONCAT(name, ' (behind)')");

        $db->transaction(function () use ($db): void {
            $db->table('users')->where('id', 1)->update(['name' => 'Ann 2']);
            try {
                $db->transaction(function () use ($db): void {
                    $db->table('users')->where('id', 2)->update(['name' => 'never']);
                    throw new RuntimeException('roll back the savepoint');
                });
            } catch (RuntimeException) {
            }
        });
        try {
            $db->transaction(function () use ($db): void {
                $db->table('users')->where('id', 3)->update(['name' => 'never']);
                throw new RuntimeException('roll back everything');
            });
        } catch (RuntimeException) {
        }

        self::assertSame('Ann 2', $this->nameOf($db, 1), 'committed: invalidated');
        self::assertSame('Bob', $this->nameOf($db, 2), 'savepoint rolled back: the cached row was kept');
        self::assertSame('Cy', $this->nameOf($db, 3), 'transaction rolled back: the cached row was kept');
        self::assertSame([0, 0, 0], [$this->pending($db, 1), $this->pending($db, 2), $this->pending($db, 3)], 'every mark was released');
    }

    public function testDeadlockRetriesInvalidateOnceAfterTheSuccessfulAttempt(): void
    {
        $db = $this->database();
        self::assertSame('Ann', $this->nameOf($db, 1));
        self::assertSame('Bob', $this->nameOf($db, 2));
        $attempts = ['a' => 0, 'b' => 0];
        $write = function (string $who, int $first, int $second) use ($db, &$attempts): void {
            $db->transaction(function () use ($db, $who, $first, $second, &$attempts): void {
                $attempts[$who]++;
                $db->table('users')->where('id', $first)->increment('score');
                self::wait(0.3);
                $db->table('users')->where('id', $second)->increment('score');
            }, attempts: 5);
        };
        self::runConcurrently(['a' => fn() => $write('a', 1, 2), 'b' => fn() => $write('b', 2, 1)]);

        self::assertGreaterThan(2, $attempts['a'] + $attempts['b'], 'one transaction deadlocked and was retried');
        self::assertSame(2, $db->table('users')->find(1)['score'] ?? null);
        self::assertSame(2, $db->table('users')->find(2)['score'] ?? null);
        self::assertSame([0, 0], [$this->pending($db, 1), $this->pending($db, 2)]);
    }

    public function testMultiLevelOnUpdateCascades(): void
    {
        $this->behind->exec('CREATE TABLE regions (code VARCHAR(10) PRIMARY KEY) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE stores (id INT PRIMARY KEY, region VARCHAR(10) NOT NULL, ref VARCHAR(20) NOT NULL UNIQUE,'
            . ' CONSTRAINT stores_region FOREIGN KEY (region) REFERENCES regions (code) ON UPDATE CASCADE) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE shelves (id INT PRIMARY KEY, region VARCHAR(10) NOT NULL, CONSTRAINT shelves_store FOREIGN KEY (region) REFERENCES stores (region) ON UPDATE CASCADE) ENGINE=InnoDB');
        $this->behind->exec('CREATE INDEX stores_region_idx ON stores (region)');
        $this->behind->exec("INSERT INTO regions VALUES ('north'), ('south')");
        $this->behind->exec("INSERT INTO stores VALUES (1, 'north', 'n1'), (2, 'south', 's1')");
        $this->behind->exec("INSERT INTO shelves VALUES (10, 'north'), (20, 'south')");
        $db = $this->database(tables: ['stores', 'shelves']);
        self::assertSame('north', $db->table('stores')->find(1)['region'] ?? null);
        self::assertSame('north', $db->table('shelves')->find(10)['region'] ?? null);
        self::assertSame('south', $db->table('shelves')->find(20)['region'] ?? null);

        $db->table('regions')->where('code', 'north')->update(['code' => 'arctic']);

        self::assertSame('arctic', $db->table('stores')->find(1)['region'] ?? null, 'first level');
        self::assertSame('arctic', $db->table('shelves')->find(10)['region'] ?? null, 'second level');
        self::assertSame('south', $db->table('shelves')->find(20)['region'] ?? null, 'unrelated rows keep their entries');
    }

    public function testSeveralWorkersWritingTheSameRow(): void
    {
        $workers = [$this->database(), $this->database(), $this->database()];
        $tasks = [];
        foreach ($workers as $i => $db) {
            $tasks[$i] = function () use ($db): void {
                for ($n = 0; $n < 20; $n++) {
                    $db->table('users')->where('id', 1)->increment('score');
                    $db->table('users')->find(1);
                }
            };
        }
        self::runConcurrently($tasks);

        foreach ($workers as $db) {
            self::assertSame(60, $db->table('users')->find(1)['score'] ?? null);
            self::assertSame(0, $this->pending($db, 1));
        }
    }

    public function testBulkInsertsWithGeneratedKeysClearCachedAbsentRows(): void
    {
        $db = $this->database(tables: ['events']);
        foreach ([1, 2, 3] as $id) {
            self::assertNull($db->table('events')->find($id));
        }

        $db->table('events')->insert([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);

        self::assertSame(['a', 'b', 'c'], [$db->table('events')->find(1)['name'] ?? null, $db->table('events')->find(2)['name'] ?? null, $db->table('events')->find(3)['name'] ?? null]);
    }

    public function testRestartingTheApplicationKeepsTheCacheConsistent(): void
    {
        $first = $this->database();
        self::assertSame('Ann', $this->nameOf($first, 1));
        $first->table('users')->where('id', 2)->update(['name' => 'Bob 2']);
        self::assertSame('Bob 2', $this->nameOf($first, 2));
        $this->closeAll();

        $this->behind->exec("UPDATE users SET name = 'behind' WHERE id = 1");
        $second = $this->database();
        self::assertSame('Ann', $this->nameOf($second, 1), 'entries survive an application restart (same Redis server: no flush)');
        self::assertSame('Bob 2', $this->nameOf($second, 2));
        $second->table('users')->where('id', 1)->update(['name' => 'Ann 2']);
        self::assertSame('Ann 2', $this->nameOf($second, 1));
    }

    // --- helpers ------------------------------------------------------------------------------

    /**
     * Caches a row, starts a write and stops Redis before the commit can publish. The snapshot
     * the restarted server loads holds the pending mark, or (when taken before the mark) only the
     * old entry.
     */
    private function dieDuringAWrite(bool $snapshotAfterMark): void
    {
        $db = $this->database(strict: false);
        self::assertSame('Bob', $this->nameOf($db, 2));
        if (!$snapshotAfterMark) {
            $this->raw()->save();
        }
        $db->transaction(function () use ($db, $snapshotAfterMark): void {
            $db->table('users')->where('id', 2)->update(['name' => 'Bob 2']);
            if ($snapshotAfterMark) {
                $this->raw()->save();
            }
            $this->stopRedis(save: false);
        });
        $this->startRedis();

        self::assertSame('Bob 2', $this->nameOf($this->database(strict: false), 2));
        self::assertSame('Bob 2', $this->nameOf($db, 2));
    }

    /** @param list<string> $tables */
    private function database(bool $strict = true, int $lockTtl = 30, array $tables = ['users']): Database
    {
        $manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 8],
            'cache' => ['tables' => $tables, 'ttl' => 60, 'strict' => $strict, 'lock_ttl' => $lockTtl],
        ], null, $this->client());
        $this->managers[] = $manager;

        return $manager->connection();
    }

    private function client(): RedisClient
    {
        $client = new RedisClient(RedisConfig::fromArray(['host' => '127.0.0.1', 'port' => $this->redisPort, 'timeout' => 0.5, 'read_timeout' => 1.0, 'pool' => ['acquire_timeout' => 0.5]]));
        $this->clients[] = $client;

        return $client;
    }

    private function closeAll(): void
    {
        foreach ($this->managers as $manager) {
            $manager->close();
        }
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->managers = [];
        $this->clients = [];
    }

    private function nameOf(Database $db, int $id): ?string
    {
        $name = $db->table('users')->find($id)['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function behindName(int $id): mixed
    {
        $statement = $this->behind->query('SELECT name FROM users WHERE id = ' . $id);

        return $statement === false ? null : $statement->fetchColumn();
    }

    private function pending(Database $db, int $id): int
    {
        $cache = new RowCache($this->client(), $db->config->cache, 'default');
        $value = $this->raw()->get($cache->versionKey('users', [$id]));
        if (!is_string($value)) {
            return 0;
        }
        $pending = explode(':', $value, 2)[1] ?? '0';

        return (int) $pending;
    }

    private function raw(): Redis
    {
        $redis = new Redis();
        $redis->connect('127.0.0.1', $this->redisPort, 1.0);

        return $redis;
    }

    private function startRedis(): void
    {
        $args = ['--port', (string) $this->redisPort, '--bind', '127.0.0.1', '--dir', $this->redisDir, '--dbfilename', 'dump.rdb', '--save', '', '--appendonly', 'no'];
        if (php_uname('m') === 'aarch64') {
            $args = array_merge($args, ['--ignore-warnings', 'ARM64-COW-BUG']);
        }
        $this->redisServer = new ProcessRunner('redis-server', $args, [], null);
        $this->redisServer->waitForPort('127.0.0.1', $this->redisPort, 10.0);
    }

    private function stopRedis(bool $save): void
    {
        if ($this->redisServer === null) {
            return;
        }
        try {
            $redis = $this->raw();
            if ($save) {
                $redis->save();
            }
            $redis->rawcommand('SHUTDOWN', 'NOSAVE');
        } catch (Throwable) {
            // the server closes the connection while shutting down
        }
        $this->redisServer->stop(5.0);
        $this->redisServer = null;
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->redisPort, $errno, $errstr, 0.1);
            if ($socket === false) {
                break;
            }
            fclose($socket);
            self::wait(0.1);
        }
    }
}
