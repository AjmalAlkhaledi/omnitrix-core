<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Database;

use Omnitrix\Core\Database\Cache\CacheCoordinator;
use Omnitrix\Core\Database\Cache\QueryLookup;
use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_column;
use function uniqid;

/**
 * Query result cache with value tags: a cached query is invalidated only by writes to rows whose
 * tracked column holds a value the query filters on — before or after the write.
 */
#[CoversClass(CacheCoordinator::class)]
#[CoversClass(RowCache::class)]
#[CoversClass(QueryLookup::class)]
#[CoversClass(Builder::class)]
final class QueryCacheTest extends TestCase
{
    private const string DB = 'omnitrix_query_cache_test';

    private PDO $behind;

    private RedisClient $redis;

    private DatabaseManager $manager;

    private Database $db;

    protected function setUp(): void
    {
        parent::setUp();
        $root = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort(), Services::dbUser(), Services::dbPassword());
        $root->exec('DROP DATABASE IF EXISTS `' . self::DB . '`');
        $root->exec('CREATE DATABASE `' . self::DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->behind = new PDO('mysql:host=' . Services::dbHost() . ';port=' . Services::dbPort() . ';dbname=' . self::DB, Services::dbUser(), Services::dbPassword(), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->behind->exec('CREATE TABLE users (id INT PRIMARY KEY, name VARCHAR(20) NOT NULL) ENGINE=InnoDB');
        $this->behind->exec("CREATE TABLE posts (id INT PRIMARY KEY, user_id INT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', title VARCHAR(50) NOT NULL, views INT NOT NULL DEFAULT 0,"
            . ' CONSTRAINT posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $this->behind->exec("INSERT INTO users VALUES (1, 'Ann'), (2, 'Bob'), (3, 'Cy')");
        $this->behind->exec("INSERT INTO posts (id, user_id, status, title) VALUES (10, 1, 'active', 'A1'), (11, 1, 'draft', 'A2'), (20, 2, 'active', 'B1'), (30, 3, 'active', 'C1')");

        $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'querycache-' . uniqid() . ':']));
        $this->manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 8],
            'cache' => ['queries' => ['posts' => ['user_id', 'status']], 'ttl' => 60],
        ], null, $this->redis);
        $this->db = $this->manager->connection();
    }

    protected function tearDown(): void
    {
        $this->manager->close();
        $this->redis->close();
        parent::tearDown();
    }

    public function testOnlyWritesToMatchingRowsInvalidate(): void
    {
        self::assertSame(['A1', 'A2'], $this->titles(1));
        self::assertSame(['B1'], $this->titles(2));
        $this->behind("UPDATE posts SET title = CONCAT(title, '*')");
        self::assertSame(['A1', 'A2'], $this->titles(1), 'served from the cache');

        $this->db->table('posts')->where('id', 20)->update(['title' => 'B1 edited']);

        self::assertSame(['A1', 'A2'], $this->titles(1), "a write to user 2's post leaves user 1's query cached");
        self::assertSame(['B1 edited'], $this->titles(2));

        $this->db->table('posts')->where('id', 11)->update(['title' => 'A2 edited']);
        self::assertSame(['A1*', 'A2 edited'], $this->titles(1));
    }

    public function testQueriesWithoutOrderOnATableThatAlsoHasARowCacheAreCached(): void
    {
        // A condition on a non-key column first looks like a key lookup; it must fall back to the
        // query cache, not to an uncached read.
        $manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 2],
            'cache' => ['tables' => ['posts'], 'queries' => ['posts' => ['user_id']], 'ttl' => 60],
        ], null, $this->redis);
        try {
            $db = $manager->connection();
            $titles = static fn(): array => array_column($db->table('posts')->where('user_id', 2)->cache(60)->get(), 'title');
            self::assertSame(['B1'], $titles());
            $this->behind("UPDATE posts SET title = 'behind' WHERE id = 20");
            self::assertSame(['B1'], $titles(), 'served from the query cache');
            self::assertSame(1, $db->cacheStats()['hits']);
        } finally {
            $manager->close();
        }
    }

    public function testRowsMovingInAndOutOfAResult(): void
    {
        self::assertSame(['A1', 'A2'], $this->titles(1));
        self::assertSame(['B1'], $this->titles(2));

        $this->db->table('posts')->where('id', 20)->update(['user_id' => 1]);

        self::assertSame(['A1', 'A2', 'B1'], $this->titles(1), 'the new value invalidates the query the row joins');
        self::assertSame([], $this->titles(2), 'the old value invalidates the query the row leaves');

        $this->db->table('posts')->insert(['id' => 12, 'user_id' => 1, 'status' => 'active', 'title' => 'A3']);
        self::assertSame(['A1', 'A2', 'A3', 'B1'], $this->titles(1));

        $this->db->table('posts')->whereIn('id', [10, 12])->delete();
        self::assertSame(['A2', 'B1'], $this->titles(1));
    }

    public function testTextValuesCompareLikeTheColumnCollation(): void
    {
        self::assertSame(['A1', 'B1', 'C1'], $this->byStatus('active'));
        self::assertSame(['A2'], $this->byStatus('draft'));

        $this->db->table('posts')->where('id', 11)->update(['status' => 'ACTIVE ']);

        self::assertSame(['A1', 'A2', 'B1', 'C1'], $this->byStatus('active'), "'ACTIVE ' equals 'active' in utf8mb4_unicode_ci");
        self::assertSame([], $this->byStatus('draft'));
        self::assertSame(['A1', 'A2', 'B1', 'C1'], $this->byStatus('Active'));
    }

    public function testValuesWithoutATagInvalidateTheWholeColumn(): void
    {
        self::assertSame(['A1', 'A2'], $this->titles(1));
        self::assertSame(['A2'], $this->byStatus('draft'));
        $this->behind("UPDATE posts SET title = CONCAT(title, '*')");

        $this->db->table('posts')->insert(['id' => 40, 'user_id' => 3, 'title' => 'C2']); // status: database default

        self::assertSame(['A2*', 'C2'], $this->byStatus('draft'), 'an omitted column could hold any value');
        self::assertSame(['A1', 'A2'], $this->titles(1), 'queries on other columns are unaffected');

        $this->db->table('posts')->where('id', 30)->increment('user_id', 0);
        self::assertSame(['A1*', 'A2*'], $this->titles(1), 'computed values invalidate every query on the column');
    }

    public function testCascadesAggregatesAndUncachedShapes(): void
    {
        $count = fn(): int => $this->db->table('posts')->where('user_id', 1)->cache(60)->count();
        self::assertSame(2, $count());
        self::assertTrue($this->db->table('posts')->where('user_id', 2)->cache(60)->exists());
        $this->behind('DELETE FROM posts WHERE id = 11');
        self::assertSame(2, $count(), 'aggregates are cached');

        $this->db->table('users')->where('id', 1)->delete();
        self::assertSame(0, $count(), 'ON DELETE CASCADE from an uncached parent invalidates the child queries');

        $or = fn(): array => array_column($this->db->table('posts')->where('user_id', 2)->orWhere('user_id', 3)->cache(60)->get(), 'title');
        $untracked = fn(): array => array_column($this->db->table('posts')->where('title', 'C1')->cache(60)->get(), 'views');
        self::assertSame(['B1', 'C1'], $or());
        self::assertSame([0], $untracked());
        $this->behind("UPDATE posts SET title = 'C1', views = 5 WHERE id = 30");
        self::assertSame(['B1', 'C1'], $or(), 'OR queries are not cached');
        self::assertSame([5], $untracked(), 'queries without a tracked column are not cached');
    }

    public function testTransactionsBypassAndPublishOnCommit(): void
    {
        self::assertSame(['A1', 'A2'], $this->titles(1));
        $this->db->transaction(function (): void {
            $this->db->table('posts')->where('id', 30)->update(['user_id' => 1]);
            self::assertSame(['A1', 'A2', 'C1'], $this->titles(1), 'the transaction sees its own write');
        });
        self::assertSame(['A1', 'A2', 'C1'], $this->titles(1));
        self::assertSame([], $this->titles(3));
    }

    public function testBulkMovesAndChangesOfNonFilterColumns(): void
    {
        self::assertSame(['A1', 'A2'], $this->titles(1));
        self::assertSame(['B1'], $this->titles(2));

        $this->db->table('posts')->where('user_id', 1)->update(['title' => 'New title']);
        self::assertSame(['New title', 'New title'], $this->titles(1), 'changing another column of matching rows invalidates');
        self::assertSame(['B1'], $this->titles(2));

        $this->db->table('posts')->where('user_id', 1)->update(['user_id' => 2]);
        self::assertSame([], $this->titles(1), 'the rows left user 1');
        self::assertSame(['New title', 'New title', 'B1'], $this->titles(2), 'and joined user 2');
    }

    public function testPaginationAndAggregatesFollowEveryKindOfWrite(): void
    {
        $page = fn(int $number): array => $this->db->table('posts')->where('user_id', 1)->orderBy('id')->limit(1)->offset($number - 1)->cache(60)->get();
        $count = fn(): int => $this->db->table('posts')->where('user_id', 1)->cache(60)->count();
        $sum = fn(): float => (float) $this->db->table('posts')->where('user_id', 1)->cache(60)->sum('views'); // SUM() is DECIMAL
        $exists = fn(): bool => $this->db->table('posts')->where('user_id', 1)->where('status', 'archived')->cache(60)->exists();
        $state = fn(): array => [array_column($page(1), 'title'), array_column($page(2), 'title'), $count(), $sum(), $exists()];

        self::assertSame([['A1'], ['A2'], 2, 0.0, false], $state());

        $this->db->table('posts')->insert(['id' => 5, 'user_id' => 1, 'status' => 'archived', 'title' => 'A0', 'views' => 7]);
        self::assertSame([['A0'], ['A1'], 3, 7.0, true], $state(), 'after an insert');

        $this->db->table('posts')->where('id', 10)->update(['views' => 3]);
        self::assertSame([['A0'], ['A1'], 3, 10.0, true], $state(), 'after an update');

        $this->db->table('posts')->where('id', 5)->delete();
        self::assertSame([['A1'], ['A2'], 2, 3.0, false], $state(), 'after a delete');
    }

    public function testJoinsAreNeverCached(): void
    {
        $joined = fn(): array => array_column($this->db->table('posts')->join('users', 'users.id', '=', 'posts.user_id')->where('posts.user_id', 1)->select('users.name')->cache(60)->get(), 'name');
        self::assertSame(['Ann', 'Ann'], $joined());
        $this->behind("UPDATE users SET name = 'Ann B.' WHERE id = 1");
        self::assertSame(['Ann B.', 'Ann B.'], $joined(), 'a change in the joined table is always visible');
    }

    /** @return list<mixed> */
    private function titles(int $user): array
    {
        return array_column($this->db->table('posts')->where('user_id', $user)->orderBy('id')->cache(60)->get(), 'title');
    }

    /** @return list<mixed> */
    private function byStatus(string $status): array
    {
        return array_column($this->db->table('posts')->where('status', $status)->orderBy('id')->cache(60)->get(), 'title');
    }

    private function behind(string $sql): void
    {
        $this->behind->exec($sql);
    }
}
