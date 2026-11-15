<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Database;

use Closure;
use Omnitrix\Core\Database\Cache\CacheConfig;
use Omnitrix\Core\Database\Cache\Mutation;
use Omnitrix\Core\Database\Driver\MySQL\MySqlGrammar;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\CacheAwareExecutorInterface;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/** Which queries count as lookups by key, what writes hand to the cache, and cache configuration. */
#[CoversClass(Builder::class)]
#[CoversClass(CacheConfig::class)]
#[CoversClass(Mutation::class)]
final class RowCacheShapesTest extends TestCase
{
    /** @return iterable<string, array{Closure(Builder): Builder, array{columns: list<string>, keys: list<list<int|string>>}|null}> */
    public static function readShapes(): iterable
    {
        yield 'find' => [static fn(Builder $q) => $q->where('id', 5)->limit(1), ['columns' => ['id'], 'keys' => [[5]]]];
        yield 'qualified column' => [static fn(Builder $q) => $q->where('users.id', '7'), ['columns' => ['id'], 'keys' => [['7']]]];
        yield 'quoted column' => [static fn(Builder $q) => $q->where('`users`.`id`', 7), ['columns' => ['id'], 'keys' => [[7]]]];
        yield 'in' => [static fn(Builder $q) => $q->whereIn('id', [1, 2]), ['columns' => ['id'], 'keys' => [[1], [2]]]];
        yield 'composite' => [static fn(Builder $q) => $q->where('a', 1)->where('b', 'x'), ['columns' => ['a', 'b'], 'keys' => [[1, 'x']]]];
        yield 'other table column' => [static fn(Builder $q) => $q->where('posts.id', 1), null];
        yield 'or' => [static fn(Builder $q) => $q->where('id', 1)->orWhere('id', 2), null];
        yield 'not in' => [static fn(Builder $q) => $q->whereNotIn('id', [1]), null];
        yield 'range' => [static fn(Builder $q) => $q->where('id', '>', 1), null];
        yield 'repeated column' => [static fn(Builder $q) => $q->where('id', 1)->where('id', 2), null];
        yield 'selected columns' => [static fn(Builder $q) => $q->select('name')->where('id', 1), null];
        yield 'join' => [static fn(Builder $q) => $q->join('posts', 'posts.user_id', '=', 'users.id')->where('users.id', 1), null];
        yield 'order' => [static fn(Builder $q) => $q->whereIn('id', [1, 2])->orderBy('id'), null];
        yield 'limit with several keys' => [static fn(Builder $q) => $q->whereIn('id', [1, 2])->limit(1), null];
        yield 'locking read' => [static fn(Builder $q) => $q->where('id', 1)->lockForUpdate(), null];
        yield 'null value' => [static fn(Builder $q) => $q->where('id', null), null];
        yield 'no where' => [static fn(Builder $q) => $q, null];
    }

    /**
     * @param Closure(Builder): Builder                                         $build
     * @param array{columns: list<string>, keys: list<list<int|string>>}|null $expected
     */
    #[DataProvider('readShapes')]
    public function testReadKeyLookups(Closure $build, ?array $expected): void
    {
        self::assertSame($expected, $build($this->builder())->readKeyLookup());
    }

    public function testWritesDescribeTheirRowsAndRewriteByKey(): void
    {
        $mutations = [];
        $builder = function () use (&$mutations): Builder {
            return $this->builder($mutations);
        };

        $builder()->where('status', 'inactive')->orderBy('id')->limit(10)->update(['status' => 'active']);
        $builder()->whereIn('id', [3, 4])->delete();
        $builder()->insert([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]);

        [$update, $delete, $insert] = $mutations;
        self::assertSame(Mutation::UPDATE, $update->kind);
        // An equality on a non-key column: the coordinator compares the columns with the table's key
        // and selects the rows first when they differ.
        self::assertSame(['columns' => ['status'], 'keys' => [['inactive']]], $update->keyLookup);
        self::assertSame(['status'], $update->updatedColumns);
        self::assertNotNull($update->selectKeys);
        self::assertSame(['select `id` from `users` where `status` = ? order by `id` asc limit 10 for update', ['inactive']], ($update->selectKeys)(['id']));
        self::assertNotNull($update->byKeys);
        self::assertSame(['update `users` set `status` = ? where `id` in (?, ?)', ['active', 5, 6]], ($update->byKeys)(['id'], [[5], [6]]));
        self::assertSame(['update `users` set `status` = ? where (`a` = ? and `b` = ?) or (`a` = ? and `b` = ?)', ['active', 1, 'x', 2, 'y']], ($update->byKeys)(['a', 'b'], [[1, 'x'], [2, 'y']]));

        self::assertSame(['columns' => ['id'], 'keys' => [[3], [4]]], $delete->keyLookup);
        self::assertSame('delete from `users` where `id` in (?, ?)', $delete->sql);

        self::assertTrue($insert->isInsert());
        self::assertSame([['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']], $insert->insertedRows);
    }

    public function testCacheConfiguration(): void
    {
        $config = CacheConfig::fromArray(['tables' => ['users', 'memberships' => ['user_id', 'team_id'], 'tags' => 'slug'], 'ttl' => 120], 'default');
        self::assertTrue($config->enabled);
        self::assertSame(['users' => null, 'memberships' => ['user_id', 'team_id'], 'tags' => ['slug']], $config->tables);
        self::assertSame([120, 60, 30, true, true, 'dbc'], [$config->ttl, $config->negativeTtl, $config->lockTtl, $config->negative, $config->strict, $config->prefix], 'strict by default');
        self::assertTrue($config->caches('users'));
        self::assertFalse($config->caches('orders'));

        self::assertFalse(CacheConfig::fromArray(null, 'default')->enabled);
        self::assertFalse(CacheConfig::fromArray(['tables' => ['users'], 'enabled' => false], 'default')->enabled);
        self::assertFalse(CacheConfig::fromArray(['tables' => []], 'default')->enabled, 'no tables: nothing to cache');

        foreach ([['ttl' => 0], ['tables' => 'users'], ['tables' => ['users' => []]], ['unknown' => 1], ['prefix' => 'a b'], ['strict' => 'yes']] as $bad) {
            try {
                CacheConfig::fromArray($bad, 'default');
                self::fail('accepted ' . json_encode($bad));
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString("'database.default.cache", $e->getMessage());
            }
        }
    }

    /** @param list<Mutation> $mutations */
    private function builder(array &$mutations = []): Builder
    {
        $executor = new class ($mutations) implements CacheAwareExecutorInterface {
            /** @param list<Mutation> $mutations */
            public function __construct(private array &$mutations) {}

            public function select(string $sql, array $bindings = []): array
            {
                return [];
            }

            public function execute(string $sql, array $bindings = []): int
            {
                return 0;
            }

            public function insert(string $sql, array $bindings = []): int
            {
                return 0;
            }

            public function selectByKeys(string $table, array $columns, array $keys, Closure $fallback): array
            {
                return $fallback();
            }

            public function selectCached(string $table, string $sql, array $bindings, array $predicates, int $ttl, Closure $fallback): array
            {
                return $fallback();
            }

            public function mutate(Mutation $mutation): int
            {
                $this->mutations[] = $mutation;

                return 1;
            }

            /** @return list<Mutation> */
            public function recorded(): array
            {
                return $this->mutations;
            }
        };

        return new Builder(new MySqlGrammar(), $executor, 'users');
    }
}
