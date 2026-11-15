<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Database;

use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\TimeoutException;
use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Database\Driver\MySQL\MySqlDriver;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\DeadlockException;
use Omnitrix\Core\Database\Exception\PoolExhaustedException;
use Omnitrix\Core\Database\Exception\QueryException;
use Omnitrix\Core\Database\Exception\QueryTimeoutException;
use Omnitrix\Core\Database\Exception\UniqueConstraintViolationException;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

use function array_filter;
use function array_unique;
use function array_values;
use function dirname;
use function hrtime;
use function is_array;
use function is_int;
use function is_string;
use function stripos;

/**
 * Database acceptance tests against a real MariaDB (bin/services): CRUD, injection safety,
 * pooling, transactions, failure classification, statement timeouts and cancellation.
 */
#[CoversNothing]
final class MySqlDatabaseTest extends TestCase
{
    private static ?Database $db = null;

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function config(array $overrides = []): array
    {
        return $overrides + [
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => Services::dbName(),
            'username' => Services::dbUser(), 'password' => Services::dbPassword(),
            'pool' => ['min' => 1, 'max' => 10, 'acquire_timeout' => 2.0, 'validation_interval' => 0.0, 'eviction_interval' => 3600.0],
            'connect_timeout' => 2.0, 'statement_timeout' => 5.0,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function makeDatabase(array $overrides = [], string $name = 'default'): Database
    {
        return new Database(DatabaseConfig::fromArray(self::config($overrides), $name), new MySqlDriver());
    }

    public static function setUpBeforeClass(): void
    {
        try {
            self::$db = self::makeDatabase();
            self::$db->execute('DROP TABLE IF EXISTS omni_users');
            self::$db->execute('CREATE TABLE omni_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, balance INT NOT NULL DEFAULT 0, tag VARCHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        } catch (ConnectionException $e) {
            Services::failUnreachable('MariaDB', $e->getMessage());
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->execute('DROP TABLE IF EXISTS omni_users');
        self::$db?->close(2.0);
        self::$db = null;
    }

    private static function db(): Database
    {
        return self::$db ?? self::fail('database not initialised');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::db()->execute('TRUNCATE omni_users');
    }

    protected function tearDown(): void
    {
        ContextStorage::unbind();
        parent::tearDown();
    }

    public function testCrudThroughTheBuilder(): void
    {
        $db = self::db();
        $id = $db->table('omni_users')->insertGetId(['email' => 'a@x.io', 'name' => 'Ann', 'balance' => 10]);
        self::assertGreaterThan(0, $id);
        self::assertSame(2, $db->table('omni_users')->insert([['email' => 'b@x.io', 'name' => 'Bob', 'tag' => null], ['email' => 'c@x.io', 'name' => 'Cid', 'tag' => 't']]));

        $row = $db->table('omni_users')->find($id);
        self::assertNotNull($row);
        self::assertSame(['id' => $id, 'email' => 'a@x.io', 'name' => 'Ann', 'balance' => 10, 'tag' => null], array_intersect_key($row, array_flip(['id', 'email', 'name', 'balance', 'tag'])));
        self::assertSame(3, $db->table('omni_users')->count());
        self::assertSame(['Ann', 'Bob'], $db->table('omni_users')->whereIn('email', ['a@x.io', 'b@x.io'])->orderBy('name')->pluck('name'));
        self::assertSame(1, $db->table('omni_users')->where('id', $id)->update(['name' => 'Anne', 'balance' => 11]));
        self::assertSame(1, $db->table('omni_users')->where('id', $id)->increment('balance', 4));
        self::assertSame(15, $db->table('omni_users')->where('id', $id)->value('balance'));
        self::assertTrue($db->table('omni_users')->where('tag', 't')->exists());
        self::assertSame(1, $db->table('omni_users')->where('email', 'c@x.io')->delete());
        self::assertSame(2, $db->table('omni_users')->count());
        self::assertEqualsWithDelta(15.0, $db->table('omni_users')->sum('balance'), 0.0001, 'SUM of integers arrives as DECIMAL');
        self::assertSame([['name' => 'Anne']], $db->select('select name from omni_users where id = ?', [$id]));
    }

    public function testBindingsDefeatSqlInjectionAndConstraintErrorsAreClassified(): void
    {
        $db = self::db();
        $db->table('omni_users')->insert(['email' => 'victim@x.io', 'name' => 'V']);
        $payload = "' OR '1'='1";
        self::assertNull($db->table('omni_users')->where('email', $payload)->first());
        self::assertSame(0, $db->table('omni_users')->where('name', "x'; DROP TABLE omni_users; --")->delete());
        self::assertSame(1, $db->table('omni_users')->count(), 'table and row survived');
        self::assertNull($db->selectOne('select * from omni_users where email = ?', [$payload]));

        try {
            $db->table('omni_users')->insert(['email' => 'victim@x.io', 'name' => 'dup']);
            self::fail('duplicate accepted');
        } catch (UniqueConstraintViolationException $e) {
            self::assertSame(1062, $e->driverCode());
            self::assertStringNotContainsString('dup', $e->getMessage(), 'bound values do not leak into messages');
        }
        try {
            $db->select('select * from no_such_table');
            self::fail('invalid table accepted');
        } catch (QueryException $e) {
            self::assertSame('42S02', $e->sqlState());
        }
    }

    public function testPoolExhaustionIsBoundedAndRecoversWhenLeasesReturn(): void
    {
        $db = self::makeDatabase(['pool' => ['min' => 0, 'max' => 2, 'acquire_timeout' => 0.3, 'validation_interval' => 0.0, 'eviction_interval' => 3600.0]], 'small');
        try {
            $a = $db->acquire();
            $b = $db->acquire();
            $t = hrtime(true);
            try {
                $db->select('select 1');
                self::fail('third statement must not get a connection');
            } catch (PoolExhaustedException) {
            }
            self::assertGreaterThanOrEqual(0.28, (hrtime(true) - $t) / 1e9);
            self::assertSame(1, $db->stats()->timeouts);
            $a->release();
            self::assertSame([['ok' => 1]], $db->select('select 1 as ok'));
            $b->release();
            self::assertSame(2, $db->stats()->size);
        } finally {
            $db->close(1.0);
        }
    }

    public function testConcurrentQueriesOverlapAndTheLoopKeepsRunning(): void
    {
        $db = self::db();
        $ticks = 0;
        $tasks = ['ticker' => static function () use (&$ticks): int {
            for ($i = 0; $i < 10; $i++) {
                Sleep::milliseconds(30);
                $ticks++;
            }

            return $ticks;
        }];
        for ($i = 0; $i < 8; $i++) {
            $tasks['q' . $i] = static fn(): array => $db->select('select sleep(0.3) as s, connection_id() as cid');
        }
        [$results, $elapsed] = self::runConcurrently($tasks);
        self::assertLessThan(0.7, $elapsed, "8 × 300 ms queries took {$elapsed}s; they must overlap");
        self::assertSame(10, $results['ticker'], 'an unrelated coroutine progressed during the queries');
        $cids = [];
        for ($i = 0; $i < 8; $i++) {
            $rows = $results['q' . $i];
            self::assertIsArray($rows);
            $cids[] = self::int(self::assoc($rows[0] ?? null)['cid']);
        }
        self::assertCount(8, array_unique($cids), 'each coroutine held its own connection');
        self::assertSame(0, $db->stats()->inUse);
    }

    public function testTransactionCommitRollbackPinningAndSavepoints(): void
    {
        $db = self::db();
        ContextStorage::bind(RequestContext::create());

        $result = $db->transaction(static function (PooledConnection $tx) use ($db): array {
            $db->table('omni_users')->insert(['email' => 't1@x.io', 'name' => 'T1']);
            $inside = $db->selectOne('select connection_id() as cid');
            $viaLease = $tx->select('select connection_id() as cid');

            return [$inside['cid'] ?? null, $viaLease[0]['cid'] ?? null];
        });
        self::assertSame($result[0], $result[1], 'statements inside the callback run on the pinned connection');
        self::assertSame(1, $db->table('omni_users')->where('email', 't1@x.io')->count(), 'committed');

        try {
            $db->transaction(static function () use ($db): int {
                $inserted = $db->table('omni_users')->insert(['email' => 't2@x.io', 'name' => 'T2']);
                if ($inserted === 1) {
                    throw new RuntimeException('business rule failed');
                }

                return $inserted;
            });
            self::fail('exception must propagate');
        } catch (RuntimeException $e) {
            self::assertSame('business rule failed', $e->getMessage());
        }
        self::assertSame(0, $db->table('omni_users')->where('email', 't2@x.io')->count(), 'rolled back');

        $db->transaction(static function () use ($db): void {
            $db->table('omni_users')->insert(['email' => 'outer@x.io', 'name' => 'O']);
            try {
                $db->transaction(static function () use ($db): void {
                    $db->table('omni_users')->insert(['email' => 'inner@x.io', 'name' => 'I']);
                    throw new RuntimeException('inner fails');
                });
            } catch (RuntimeException) {
            }
        });
        self::assertSame(1, $db->table('omni_users')->where('email', 'outer@x.io')->count(), 'outer committed');
        self::assertSame(0, $db->table('omni_users')->where('email', 'inner@x.io')->count(), 'inner rolled back to savepoint');
        self::assertSame(0, $db->stats()->inUse, 'every transaction returned its connection');
    }

    public function testDeadlockIsClassifiedAndRetried(): void
    {
        $db = self::db();
        $db->table('omni_users')->insert([['email' => 'd1@x.io', 'name' => 'D1'], ['email' => 'd2@x.io', 'name' => 'D2']]);
        $attempts = ['a' => 0, 'b' => 0];
        $lock = static function (string $first, string $second, string $key) use ($db, &$attempts): string {
            return $db->transaction(static function () use ($db, $first, $second, $key, &$attempts): string {
                $attempts[$key]++;
                $db->table('omni_users')->where('email', $first)->increment('balance');
                Sleep::milliseconds(150);
                $db->table('omni_users')->where('email', $second)->increment('balance');

                return 'ok';
            }, attempts: 3);
        };

        $outcomes = Async::allSettled([
            'a' => static fn(): string => $lock('d1@x.io', 'd2@x.io', 'a'),
            'b' => static function () use ($lock): string {
                Sleep::milliseconds(30);

                return $lock('d2@x.io', 'd1@x.io', 'b');
            },
        ]);
        self::assertTrue($outcomes['a']->fulfilled, (string) $outcomes['a']->reason?->getMessage());
        self::assertTrue($outcomes['b']->fulfilled, (string) $outcomes['b']->reason?->getMessage());
        self::assertGreaterThan(2, $attempts['a'] + $attempts['b'], 'one transaction deadlocked and was retried');
        self::assertSame([2, 2], [$db->table('omni_users')->where('email', 'd1@x.io')->value('balance'), $db->table('omni_users')->where('email', 'd2@x.io')->value('balance')]);

        // Without retries the deadlock surfaces as a typed exception.
        $plain = Async::allSettled([
            'a' => static fn(): string => $db->transaction(static function () use ($db): string {
                $db->table('omni_users')->where('email', 'd1@x.io')->increment('balance');
                Sleep::milliseconds(150);
                $db->table('omni_users')->where('email', 'd2@x.io')->increment('balance');

                return 'ok';
            }),
            'b' => static function () use ($db): string {
                Sleep::milliseconds(30);

                return $db->transaction(static function () use ($db): string {
                    $db->table('omni_users')->where('email', 'd2@x.io')->increment('balance');
                    Sleep::milliseconds(150);
                    $db->table('omni_users')->where('email', 'd1@x.io')->increment('balance');

                    return 'ok';
                });
            },
        ]);
        $failures = array_values(array_filter($plain, static fn($o) => $o->isRejected()));
        self::assertCount(1, $failures);
        self::assertInstanceOf(DeadlockException::class, $failures[0]->reason);
    }

    public function testConnectTimeoutAndUnavailableDatabase(): void
    {
        $blackhole = self::makeDatabase(['host' => '10.255.255.1', 'connect_timeout' => 1.0], 'blackhole');
        $t = hrtime(true);
        try {
            $blackhole->select('select 1');
            self::fail('blackhole must time out');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('timed out', $e->getMessage());
        }
        $elapsed = (hrtime(true) - $t) / 1e9;
        self::assertGreaterThanOrEqual(0.9, $elapsed);
        self::assertLessThan(3.0, $elapsed);
        $blackhole->close(0.5);

        $down = self::makeDatabase(['port' => 33399], 'down');
        $down->warmUp(); // must not throw: the database may come up later
        try {
            $down->select('select 1');
            self::fail('closed port must fail');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }
        self::assertSame(0, $down->stats()->size, 'no half-open connections kept');
        $down->close(0.5);
    }

    public function testStatementTimeoutAndCancellationDiscardTheConnection(): void
    {
        $db = self::makeDatabase(['statement_timeout' => 0.3], 'timeouts');
        try {
            // MariaDB interrupts SLEEP() with error 1969; MySQL makes an interrupted SLEEP() return 1
            // without an error, so a CPU-bound statement is used there (error 3024).
            $version = $db->select('select version() as v')[0]['v'] ?? '';
            $mariaDb = is_string($version) && stripos($version, 'mariadb') !== false;
            $t = hrtime(true);
            try {
                $db->select($mariaDb ? 'select sleep(2)' : "select benchmark(10000000000, md5('x'))");
                self::fail('statement should be interrupted by the server');
            } catch (QueryTimeoutException $e) {
                self::assertSame($mariaDb ? 1969 : 3024, $e->driverCode());
            }
            self::assertLessThan(1.0, (hrtime(true) - $t) / 1e9);
            self::assertSame([['ok' => 1]], $db->select('select 1 as ok'), 'connection still usable after a server-side timeout');

            $destroyedBefore = $db->stats()->destroyed;
            $t = hrtime(true);
            try {
                Async::withTimeout(static fn(): array => $db->select('select sleep(5)'), 0.2);
                self::fail('expected timeout');
            } catch (TimeoutException) {
            }
            self::assertLessThan(1.0, (hrtime(true) - $t) / 1e9, 'cancellation interrupted the query wait');
            Sleep::milliseconds(50);
            self::assertSame($destroyedBefore + 1, $db->stats()->destroyed, 'an interrupted connection is discarded, never reused');
            self::assertSame([['ok' => 1]], $db->select('select 1 as ok'));
        } finally {
            $db->close(1.0);
        }
    }

    public function testServerKilledConnectionsAreReplacedTransparently(): void
    {
        // A one-connection pool makes the killed connection the one that is checked out next.
        $db = self::makeDatabase(['pool' => ['min' => 0, 'max' => 1, 'acquire_timeout' => 2.0, 'validation_interval' => 0.0, 'eviction_interval' => 3600.0]], 'single');
        try {
            $cid = $db->selectOne('select connection_id() as cid')['cid'] ?? null;
            self::assertIsInt($cid);
            self::db()->execute('KILL ' . $cid);
            Sleep::milliseconds(50);
            // validation_interval = 0 → every checkout pings; the dead connection is replaced.
            self::assertSame([['ok' => 1]], $db->select('select 1 as ok'));
            self::assertSame(1, $db->stats()->validationFailures);
            self::assertSame(2, $db->stats()->created, 'a fresh connection replaced the killed one');
        } finally {
            $db->close(1.0);
        }
    }

    public function testDatabaseRestartRecovery(): void
    {
        $db = self::db();
        self::assertSame([['ok' => 1]], $db->select('select 1 as ok'));
        $restart = ProcessRunner::command([dirname(__DIR__, 4) . '/bin/services', 'restart-mysql']);
        $code = $restart->wait(120.0);
        self::assertSame(0, $code, 'restart-mysql failed: ' . $restart->stderr());

        $recovered = null;
        for ($i = 0; $i < 20 && $recovered === null; $i++) {
            try {
                $recovered = $db->select('select 1 as ok');
            } catch (ConnectionException) {
                Sleep::milliseconds(500); // the server may still be starting
            }
        }
        self::assertSame([['ok' => 1]], $recovered, 'queries succeed again after the database restarted');
        self::assertSame(0, $db->stats()->inUse);
        $db->execute('CREATE TABLE IF NOT EXISTS omni_users (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, balance INT NOT NULL DEFAULT 0, tag VARCHAR(64) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    }

    public function testPreparedStatementsAreReusedPerConnection(): void
    {
        $db = self::makeDatabase(['pool' => ['min' => 0, 'max' => 1, 'validation_interval' => 30.0, 'eviction_interval' => 3600.0]], 'stmtcache');
        $prepares = static function () use ($db): int {
            $row = $db->selectOne("SHOW SESSION STATUS LIKE 'Com_stmt_prepare'");

            $value = is_array($row) ? ($row['Value'] ?? '0') : '0';

            return is_string($value) || is_int($value) ? (int) $value : 0;
        };
        try {
            $db->table('omni_users')->insert(['email' => 'cache@x.io', 'name' => 'C']);
            $before = $prepares();
            for ($i = 0; $i < 50; $i++) {
                self::assertSame('C', $db->table('omni_users')->where('email', 'cache@x.io')->value('name'));
            }
            $after = $prepares();
            self::assertLessThanOrEqual(3, $after - $before, '50 identical queries reuse one prepared statement (plus the status query)');

            for ($i = 0; $i < 80; $i++) { // more distinct statements than the cache holds: LRU eviction
                self::assertSame([['n' => $i]], $db->select('select ? as n /* ' . $i . ' */', [$i]));
            }
            self::assertSame('C', $db->table('omni_users')->where('email', 'cache@x.io')->value('name'), 'evicted statements are re-prepared transparently');
        } finally {
            $db->close(1.0);
        }
    }

    public function testManagerBuildsNamedConnectionsAndCrossCoroutineIsolation(): void
    {
        $manager = new DatabaseManager(['default' => self::config(), 'reporting' => self::config(['pool' => ['max' => 1]])]);
        try {
            self::assertSame(['default', 'reporting'], $manager->names());
            self::assertSame(1, $manager->connection('reporting')->config->pool->max);
            $tasks = [];
            for ($i = 0; $i < 6; $i++) {
                $tasks[$i] = static function () use ($manager, $i): array {
                    ContextStorage::bind(RequestContext::create());

                    return $manager->connection()->transaction(static function () use ($manager, $i): array {
                        $manager->table('omni_users')->insert(['email' => "iso{$i}@x.io", 'name' => 'iso', 'tag' => ContextStorage::require()->id]);
                        Sleep::milliseconds(50);
                        $own = $manager->table('omni_users')->where('tag', ContextStorage::require()->id)->count();
                        $cid = $manager->connection()->selectOne('select connection_id() as cid')['cid'] ?? null;

                        return ['own' => $own, 'cid' => $cid];
                    });
                };
            }
            [$results] = self::runConcurrently($tasks);
            $cids = [];
            foreach ($results as $i => $r) {
                self::assertSame(1, $r['own'], "request {$i} saw rows from another request's transaction");
                $cids[] = self::int($r['cid']);
            }
            self::assertCount(6, array_unique($cids), 'each request pinned its own connection');
            self::assertSame(6, $manager->table('omni_users')->count());
        } finally {
            self::assertSame(0, $manager->close(2.0));
        }
    }
}
