<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Database;

use Omnitrix\Core\Database\Cache\CacheConfig;
use Omnitrix\Core\Database\Cache\CacheCoordinator;
use Omnitrix\Core\Database\Cache\CacheSchema;
use Omnitrix\Core\Database\Cache\CacheUnavailableException;
use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function array_column;
use function is_int;
use function uniqid;

/**
 * Primary-key row cache against real MariaDB and Redis. Rows are changed "behind the cache" with a
 * separate PDO connection to prove which entries are served from the cache and which were
 * invalidated.
 */
#[CoversClass(RowCache::class)]
#[CoversClass(CacheCoordinator::class)]
#[CoversClass(CacheSchema::class)]
#[CoversClass(CacheConfig::class)]
#[CoversClass(TransactionManager::class)]
#[CoversClass(Database::class)]
final class RowCacheTest extends TestCase
{
    private const string DB = 'omnitrix_row_cache_test';

    private PDO $behind;

    private RedisClient $redis;

    private DatabaseManager $manager;

    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $root = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort(), Services::dbUser(), Services::dbPassword());
        $root->exec('DROP DATABASE IF EXISTS `' . self::DB . '`');
        $root->exec('CREATE DATABASE `' . self::DB . '`');
        $this->behind = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . self::DB, Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->behind->exec('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(50) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT "active", score INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE posts (id INT PRIMARY KEY, user_id INT NOT NULL, title VARCHAR(50) NOT NULL, CONSTRAINT posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE comments (id INT PRIMARY KEY, post_id INT NOT NULL, body VARCHAR(50) NOT NULL, CONSTRAINT comments_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE memberships (user_id INT NOT NULL, team_id INT NOT NULL, role VARCHAR(10) NOT NULL, PRIMARY KEY (user_id, team_id)) ENGINE=InnoDB');
        $this->behind->exec("INSERT INTO users (id, name, status) VALUES (1, 'Ann', 'active'), (2, 'Bob', 'inactive'), (3, 'Cy', 'inactive'), (4, 'Dee', 'active')");
        $this->behind->exec("INSERT INTO posts VALUES (10, 1, 'First'), (11, 1, 'Second'), (12, 2, 'Bob post')");
        $this->behind->exec("INSERT INTO comments VALUES (100, 10, 'Nice'), (101, 12, 'Hm')");
        $this->behind->exec("INSERT INTO memberships VALUES (1, 7, 'owner'), (2, 7, 'member')");

        $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'rowcache-' . uniqid() . ':']));
        [$this->manager, $this->db] = $this->connect($this->redis);
    }

    protected function tearDown(): void
    {
        $this->manager->close();
        $this->redis->close();
        parent::tearDown();
    }

    public function testLookupsAreCachedAndWritesInvalidateOnlyTheirKeys(): void
    {
        self::assertSame('Ann', $this->nameOf(1));
        self::assertSame('Bob', $this->nameOf(2));
        $this->behindTheCache("UPDATE users SET name = 'changed' WHERE id IN (1, 2)");
        self::assertSame('Ann', $this->nameOf(1), 'served from the cache');
        self::assertSame('Bob', $this->nameOf(2), 'served from the cache');

        $this->db->table('users')->where('id', 1)->update(['name' => 'Ann B.']);

        self::assertSame('Ann B.', $this->nameOf(1), 'the written row is read again');
        self::assertSame('Bob', $this->nameOf(2), 'other rows of the table keep their cache entries');
        self::assertGreaterThanOrEqual(3, $this->db->cacheStats()['hits']);
    }

    public function testFindManyAndQualifiedColumnsUseOneRoundTrip(): void
    {
        $rows = $this->db->table('users')->whereIn('users.id', [3, 1, 99, 1])->get();
        self::assertSame([3, 1], array_column($rows, 'id'), 'requested order, duplicates and missing keys removed');
        $this->behindTheCache("UPDATE users SET name = 'changed'");
        self::assertSame(['Cy', 'Ann'], array_column($this->db->table('users')->whereIn('id', [3, 1])->get(), 'name'));
        self::assertSame('Cy', $this->db->table('users')->find(3)['name'] ?? null);
        self::assertSame('changed', $this->db->table('users')->where('id', 3)->where('status', 'inactive')->first()['name'] ?? null, 'other conditions are not cached lookups');
    }

    public function testBulkWritesInvalidateExactlyTheAffectedRows(): void
    {
        foreach ([1, 2, 3, 4] as $id) {
            $this->nameOf($id);
        }
        $this->behindTheCache("UPDATE users SET name = CONCAT(name, '*')");

        $affected = $this->db->table('users')->where('status', 'inactive')->update(['status' => 'active']);

        self::assertSame(2, $affected);
        self::assertSame(['Ann', 'Bob*', 'Cy*', 'Dee'], [$this->nameOf(1), $this->nameOf(2), $this->nameOf(3), $this->nameOf(4)], 'only users 2 and 3 matched the bulk update');
        self::assertSame(0, $this->db->table('users')->where('status', 'missing')->update(['score' => 1]));

        $this->db->table('users')->where('score', '>=', 0)->orderBy('id')->limit(1)->increment('score', 5);
        self::assertSame(5, $this->row(1)['score'] ?? null);
        self::assertSame(0, $this->row(4)['score'] ?? null);
    }

    public function testAbsentRowsAreCachedUntilAnInsertIntoTheTable(): void
    {
        self::assertNull($this->db->table('users')->find(50));
        $this->behindTheCache("INSERT INTO users (id, name) VALUES (50, 'Behind')");
        self::assertNull($this->db->table('users')->find(50), 'the absent answer is cached');

        $this->db->table('users')->insert(['id' => 51, 'name' => 'New']);

        self::assertSame('Behind', $this->nameOf(50), 'any insert into the table retires cached absent answers');
        self::assertSame('New', $this->nameOf(51));

        $this->db->table('users')->where('id', 51)->delete();
        self::assertNull($this->db->table('users')->find(51));
    }

    public function testCompositeKeysAndKeyChanges(): void
    {
        $role = fn(): mixed => $this->db->table('memberships')->where('team_id', 7)->where('user_id', 2)->first()['role'] ?? null;
        self::assertSame('member', $role());
        $this->behindTheCache("UPDATE memberships SET role = 'x' WHERE user_id = 2");
        self::assertSame('member', $role(), 'composite keys are cached, column order does not matter');
        $this->db->table('memberships')->where('user_id', 2)->where('team_id', 7)->update(['role' => 'admin']);
        self::assertSame('admin', $role());

        self::assertNull($this->db->table('users')->find(60));
        $this->db->table('users')->where('id', 4)->update(['id' => 60]);
        self::assertSame('Dee', $this->nameOf(60), 'changing a key retires cached absent answers');
        self::assertNull($this->db->table('users')->find(4));
    }

    public function testTransactionsReadTheirOwnWritesAndPublishOnCommit(): void
    {
        self::assertSame('Ann', $this->nameOf(1));
        [$results] = self::runConcurrently([
            'writer' => function (): string {
                return (string) $this->db->transaction(function (): string {
                    $this->db->table('users')->where('id', 1)->update(['name' => 'In tx']);
                    $inside = (string) $this->nameOf(1);
                    self::wait(0.3);

                    return $inside;
                });
            },
            'reader' => function (): string {
                self::wait(0.1);

                return (string) $this->nameOf(1);
            },
        ]);
        self::assertSame('In tx', $results['writer'], 'reads inside the transaction see its own write');
        self::assertSame('Ann', $results['reader'], 'other coroutines see the committed value meanwhile');
        self::assertSame('In tx', $this->nameOf(1), 'nothing stale was cached during the transaction');

        try {
            $this->db->transaction(function (): void {
                $this->db->table('users')->where('id', 1)->update(['name' => 'rolled back']);
                throw new RuntimeException('abort');
            });
        } catch (RuntimeException) {
        }
        self::assertSame('In tx', $this->nameOf(1));
    }

    public function testAfterCommitFollowsNestedTransactions(): void
    {
        $log = [];
        $this->db->afterCommit(static function () use (&$log): void {
            $log[] = 'outside';
        });
        $this->db->transaction(function () use (&$log): void {
            $this->db->afterCommit(static function () use (&$log): void {
                $log[] = 'outer';
            });
            $this->db->transaction(function () use (&$log): void {
                $this->db->afterCommit(static function () use (&$log): void {
                    $log[] = 'nested ok';
                });
            });
            try {
                $this->db->transaction(function () use (&$log): void {
                    $this->db->afterCommit(static function () use (&$log): void {
                        $log[] = 'nested rolled back';
                    });
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
            self::assertSame(['outside'], $log, 'nothing runs before the outer commit');
        });
        try {
            $this->db->transaction(function () use (&$log): void {
                $this->db->afterCommit(static function () use (&$log): void {
                    $log[] = 'outer rolled back';
                });
                throw new RuntimeException('outer');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(['outside', 'outer', 'nested ok'], $log);
    }

    public function testForeignKeyCascadesInvalidateCachedChildren(): void
    {
        self::assertSame('First', $this->db->table('posts')->find(10)['title'] ?? null);
        self::assertSame('Nice', $this->db->table('comments')->find(100)['body'] ?? null);
        self::assertSame('Hm', $this->db->table('comments')->find(101)['body'] ?? null);

        $this->db->table('users')->where('id', 1)->delete();

        self::assertNull($this->db->table('posts')->find(10), 'deleted by ON DELETE CASCADE');
        self::assertNull($this->db->table('comments')->find(100), 'deleted two levels down');
        self::assertSame('Hm', $this->db->table('comments')->find(101)['body'] ?? null, 'unrelated children keep their entries');
    }

    public function testRawWritesAndOutOfBandChanges(): void
    {
        self::assertSame(0, $this->row(2)['score'] ?? null);
        $this->db->writing('users', [2], fn(): int => $this->db->execute('UPDATE users SET score = 9 WHERE id = 2'));
        self::assertSame(9, $this->row(2)['score'] ?? null);

        self::assertSame('Cy', $this->nameOf(3));
        $this->behindTheCache("UPDATE users SET name = 'fixed' WHERE id = 3");
        self::assertSame('Cy', $this->nameOf(3), 'cached; the change happened outside the application');
        $this->db->invalidate('users', [3]);
        self::assertSame('fixed', $this->nameOf(3));
    }

    public function testTableChangesRetireAllEntriesOfTheTable(): void
    {
        self::assertSame('Ann', $this->nameOf(1));
        self::assertSame('Bob', $this->nameOf(2));
        $this->behindTheCache('SET FOREIGN_KEY_CHECKS = 0');
        $this->behindTheCache('TRUNCATE TABLE users');
        $this->behindTheCache('SET FOREIGN_KEY_CHECKS = 1');
        $this->behindTheCache("INSERT INTO users (id, name) VALUES (1, 'Recreated')");
        self::assertSame('Ann', $this->nameOf(1), 'still cached: the truncate happened behind the cache');

        $this->db->flushCache('users');

        self::assertSame('Recreated', $this->nameOf(1));
        self::assertNull($this->row(2));
        self::assertSame('First', $this->db->table('posts')->find(10)['title'] ?? null, 'other tables are not affected');
    }

    public function testFillsOfReadsOverlappingAWriteAreRejected(): void
    {
        $cache = new RowCache($this->redis, $this->db->config->cache, 'default');
        [$before] = $cache->lookup('users', [[1]]);
        self::assertFalse($before->hit);

        // A writer marks, changes and publishes while the slow reader is still working.
        $cache->mark([$cache->versionKey('users', [1])]);
        [$during] = $cache->lookup('users', [[1]]);
        self::assertFalse($during->fillable, 'readers bypass the cache while a write is pending');
        $cache->publish([$cache->versionKey('users', [1])]);

        $cache->fill('users', [['key' => [1], 'row' => ['id' => 1, 'name' => 'stale'], 'lookup' => $before]]);
        [$after] = $cache->lookup('users', [[1]]);
        self::assertFalse($after->hit, 'the stale value read before the write was not stored');
    }

    public function testConcurrentReadersNeverGoBackwards(): void
    {
        $writes = 25;
        $tasks = ['writer' => function () use ($writes): array {
            $errors = [];
            for ($i = 1; $i <= $writes; $i++) {
                $this->db->table('users')->where('id', 1)->update(['score' => $i]);
                $seen = $this->row(1)['score'] ?? null;
                if ($seen !== $i) {
                    $errors[] = "after write {$i} read " . (is_int($seen) ? $seen : 'null');
                }
            }

            return $errors;
        }];
        for ($r = 0; $r < 4; $r++) {
            $tasks["reader{$r}"] = function () use ($writes): array {
                $errors = [];
                $last = 0;
                for ($i = 0; $i < 4 * $writes; $i++) {
                    $value = $this->row(1)['score'] ?? null;
                    $value = is_int($value) ? $value : -1;
                    if ($value < $last) {
                        $errors[] = "read {$value} after {$last}";
                    }
                    $last = $value;
                    self::wait(0.002);
                }

                return $errors;
            };
        }
        [$results] = self::runConcurrently($tasks);

        foreach ($results as $name => $errors) {
            self::assertSame([], $errors, (string) $name);
        }
        self::assertSame($writes, $this->row(1)['score'] ?? null);
    }

    public function testTheDatabaseIsUsedWhenRedisFails(): void
    {
        $down = new RedisClient(RedisConfig::fromArray(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'pool' => ['acquire_timeout' => 0.2]]));
        [$manager, $db] = $this->connect($down);
        try {
            self::assertSame('Ann', $db->table('users')->find(1)['name'] ?? null);
            self::assertSame(1, $db->table('users')->where('id', 1)->update(['name' => 'still writes']));
            self::assertGreaterThan(0, $db->cacheStats()['errors']);
        } finally {
            $manager->close();
        }

        [$strictManager, $strict] = $this->connect($down, strict: true);
        try {
            $this->expectException(CacheUnavailableException::class);
            $strict->table('users')->where('id', 1)->update(['name' => 'refused']);
        } finally {
            $strictManager->close();
            $down->close();
            $statement = $this->behind->query('SELECT name FROM users WHERE id = 1');
            self::assertSame('still writes', $statement === false ? null : $statement->fetchColumn());
        }
    }

    /** @return array{DatabaseManager, Database} */
    private function connect(RedisClient $redis, bool $strict = false): array
    {
        $manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 8],
            'cache' => ['tables' => ['users', 'posts', 'comments', 'memberships'], 'ttl' => 60, 'strict' => $strict],
        ], null, $redis);

        return [$manager, $manager->connection()];
    }

    private function nameOf(int $id): ?string
    {
        $name = $this->row($id)['name'] ?? null;

        return $name === null ? null : self::string($name);
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return $this->db->table('users')->find($id);
    }

    private function behindTheCache(string $sql): void
    {
        $this->behind->exec($sql);
    }
}
