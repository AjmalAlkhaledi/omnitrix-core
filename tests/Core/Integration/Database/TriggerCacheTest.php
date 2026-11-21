<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Database;

use Omnitrix\Core\Database\Cache\CacheCoordinator;
use Omnitrix\Core\Database\Cache\CacheSchema;
use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\Database;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_column;
use function array_map;
use function uniqid;

/**
 * Triggers that write to cached tables: the rows they change cannot be known, so writes to the
 * trigger's table mark the target tables as a whole and a commit retires all their entries.
 */
#[CoversClass(CacheSchema::class)]
#[CoversClass(CacheCoordinator::class)]
#[CoversClass(RowCache::class)]
final class TriggerCacheTest extends TestCase
{
    private const string DB = 'omnitrix_trigger_cache_test';

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
        $this->behind->exec('CREATE TABLE regions (id INT PRIMARY KEY) ENGINE=InnoDB');
        $this->behind->exec('INSERT INTO regions VALUES (1), (2)');
        $this->behind->exec('CREATE TABLE counters (id INT PRIMARY KEY, kind VARCHAR(10) NOT NULL, n INT NOT NULL, region_id INT NOT NULL DEFAULT 1,'
            . ' CONSTRAINT counters_region FOREIGN KEY (region_id) REFERENCES regions (id) ON DELETE CASCADE) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE purges (id INT AUTO_INCREMENT PRIMARY KEY, region_id INT NOT NULL) ENGINE=InnoDB');
        $this->behind->exec('CREATE TRIGGER purge_region AFTER INSERT ON purges FOR EACH ROW DELETE FROM regions WHERE id = NEW.region_id');
        $this->behind->exec('CREATE TABLE orders (id INT AUTO_INCREMENT PRIMARY KEY, total INT NOT NULL) ENGINE=InnoDB');
        $this->behind->exec('CREATE TABLE order_log (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT NOT NULL) ENGINE=InnoDB');
        $this->behind->exec("INSERT INTO counters (id, kind, n, region_id) VALUES (1, 'orders', 0, 1), (2, 'deleted', 0, 1), (3, 'updates', 0, 1), (4, 'regional', 0, 2)");
        $this->behind->exec('CREATE TRIGGER orders_count AFTER INSERT ON orders FOR EACH ROW UPDATE counters SET n = n + 1 WHERE id = 1');
        $this->behind->exec('CREATE TRIGGER orders_log AFTER DELETE ON orders FOR EACH ROW INSERT INTO order_log (order_id) VALUES (OLD.id)');
        $this->behind->exec('CREATE TRIGGER log_count AFTER INSERT ON order_log FOR EACH ROW UPDATE `counters` SET n = n + 100 WHERE id = 2');
        $this->behind->exec('CREATE PROCEDURE bump_updates() UPDATE counters SET n = n + 1 WHERE id = 3');
        $this->behind->exec('CREATE TRIGGER orders_updated AFTER UPDATE ON orders FOR EACH ROW CALL bump_updates()');

        $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'trigger-cache-' . uniqid() . ':']));
        [$this->manager, $this->db] = $this->connect();
    }

    protected function tearDown(): void
    {
        $this->manager->close();
        $this->redis->close();
        parent::tearDown();
    }

    public function testATriggerOnAnUncachedTableInvalidatesTheCachedTableItWrites(): void
    {
        self::assertSame(0, $this->counter(1));
        self::assertSame(0, $this->counter(3));
        $this->behind->exec('UPDATE counters SET n = 50 WHERE id = 3');
        self::assertSame(0, $this->counter(3), 'served from the cache');

        $this->db->table('orders')->insert(['total' => 10]);

        self::assertSame(1, $this->counter(1), 'the INSERT trigger changed counters: the table was retired');
        self::assertSame(50, $this->counter(3), 'all entries of the table are retired, not only the changed row');
        self::assertSame(1, $this->counter(1), 'and cached again');
    }

    public function testNestedTriggersAndStoredProceduresAreFollowed(): void
    {
        $id = $this->db->table('orders')->insertGetId(['total' => 10]);
        self::assertSame(0, $this->counter(2));
        self::assertSame(0, $this->counter(3));

        $this->db->table('orders')->where('id', $id)->update(['total' => 20]);
        self::assertSame(1, $this->counter(3), 'UPDATE trigger calls a procedure: every configured table is retired');

        $this->db->table('orders')->where('id', $id)->delete();
        self::assertSame(100, $this->counter(2), 'DELETE trigger writes order_log, whose trigger writes counters');
    }

    public function testForeignKeyCascadesFromATriggersTargetAreFollowed(): void
    {
        self::assertSame(0, $this->counter(4));
        $this->db->table('purges')->insert(['region_id' => 2]); // trigger deletes region 2, which cascades into counters

        self::assertNull($this->counter(4), 'the cascaded delete is not served from the cache');
        self::assertSame(0, $this->counter(1));
    }

    public function testCachedQueriesOfTheTargetTableAreRetiredToo(): void
    {
        self::assertSame([0], $this->ordersCounter());
        $this->behind->exec("UPDATE counters SET n = 9 WHERE kind = 'orders'");
        self::assertSame([0], $this->ordersCounter(), 'served from the query cache');
        $this->db->table('orders')->insert(['total' => 5]);
        self::assertSame([10], $this->ordersCounter());
    }

    public function testReadersBypassTheTargetTableUntilTheWriteCommits(): void
    {
        self::assertSame(0, $this->counter(1));
        [$otherManager, $other] = $this->connect();
        try {
            $this->db->transaction(function () use ($other): void {
                $this->db->table('orders')->insert(['total' => 1]);
                self::assertSame(0, $other->table('counters')->find(1)['n'] ?? null, 'another connection reads the committed value from the database');
                self::assertSame(0, $other->table('counters')->find(1)['n'] ?? null);
            });
            self::assertSame(1, $other->table('counters')->find(1)['n'] ?? null, 'no entry filled during the transaction survives the commit');
        } finally {
            $otherManager->close();
        }
    }

    public function testWritesWithoutTriggersForTheirEventLeaveTheCacheAlone(): void
    {
        $this->db->table('order_log')->insert(['order_id' => 1]); // fires log_count
        self::assertSame(100, $this->counter(2));
        $this->behind->exec('UPDATE counters SET n = 7 WHERE id = 2');
        $this->db->table('order_log')->where('id', '>', 0)->update(['order_id' => 2]); // no UPDATE trigger on order_log
        self::assertSame(100, $this->counter(2), 'still served from the cache');
    }

    /** @return array{DatabaseManager, Database} */
    private function connect(): array
    {
        $manager = new DatabaseManager([
            'driver' => 'mariadb', 'host' => Services::dbHost(), 'port' => Services::dbPort(), 'database' => self::DB,
            'username' => Services::dbUser(), 'password' => Services::dbPassword(), 'pool' => ['min' => 0, 'max' => 4],
            'cache' => ['tables' => ['counters'], 'queries' => ['counters' => ['kind']], 'ttl' => 60],
        ], null, $this->redis);

        return [$manager, $manager->connection()];
    }

    /** @return list<int> */
    private function ordersCounter(): array
    {
        return array_map(self::int(...), array_column($this->db->table('counters')->where('kind', 'orders')->cache(60)->get(), 'n'));
    }

    private function counter(int $id): ?int
    {
        $n = $this->db->table('counters')->find($id)['n'] ?? null;

        return $n === null ? null : self::int($n);
    }
}
