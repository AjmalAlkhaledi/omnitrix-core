<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database;

use Closure;
use Omnitrix\Core\Database\Cache\CacheCoordinator;
use Omnitrix\Core\Database\Cache\CacheSchema;
use Omnitrix\Core\Database\Cache\Mutation;
use Omnitrix\Core\Database\Cache\RowCache;
use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Contracts\DriverInterface;
use Omnitrix\Core\Database\Pool\ConnectionPool;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Database\Pool\PoolStats;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\CacheAwareExecutorInterface;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Psr\Log\LoggerInterface;

use function array_values;
use function max;
use function min;

/**
 * One named database: its pool, grammar and transaction manager. Worker-scoped.
 *
 * Statements issued outside a transaction lease a connection for exactly one statement, so a
 * request that waits on other I/O between queries does not hold a connection meanwhile.
 * Inside {@see transaction()} every statement runs on the pinned connection.
 */
final class Database implements CacheAwareExecutorInterface
{
    private readonly ConnectionPool $pool;

    private readonly TransactionManager $transactions;

    private ?CacheCoordinator $cache = null;

    public function __construct(
        public readonly DatabaseConfig $config,
        private readonly DriverInterface $driver,
        ?LoggerInterface $logger = null,
        ?RedisClient $redis = null,
    ) {
        $driverRef = $driver;
        $this->pool = new ConnectionPool(
            $config->name,
            $config->pool,
            static fn() => $driverRef->connect($config),
            $logger === null ? null : static function (string $event, array $context) use ($logger): void {
                $logger->debug('db.pool.' . $event, $context);
            },
        );
        $this->transactions = new TransactionManager($config->name, function (array $marks): void {
            $this->cache?->release($marks);
        });
        if ($config->cache->enabled && $redis !== null) {
            $select = fn(string $sql, array $bindings): array => $this->select($sql, array_values($bindings));
            $this->cache = new CacheCoordinator(
                new RowCache($redis, $config->cache, $config->name),
                new CacheSchema($config->cache, $select),
                $this->transactions,
                $driver->grammar(),
                $select,
                fn(string $sql, array $bindings): int => $this->execute($sql, array_values($bindings)),
                fn(string $sql, array $bindings): int => $this->insert($sql, array_values($bindings)),
                fn(callable $callback): mixed => $this->transaction(static fn(): mixed => $callback()),
                $logger,
            );
        }
    }

    // --- row cache --------------------------------------------------------------------------

    /**
     * @internal called by the query builder for lookups by key
     *
     * @param list<string>                          $columns
     * @param list<list<int|string>>                $keys
     * @param Closure(): list<array<string, mixed>> $fallback
     *
     * @return list<array<string, mixed>>
     */
    public function selectByKeys(string $table, array $columns, array $keys, Closure $fallback): array
    {
        return $this->cache === null ? $fallback() : $this->cache->selectByKeys($table, $columns, $keys, $fallback);
    }

    /** @internal called by the query builder for every write */
    public function mutate(Mutation $mutation): int
    {
        if ($this->cache !== null) {
            return $this->cache->mutate($mutation);
        }

        return $mutation->kind === Mutation::INSERT_GET_ID ? $this->insert($mutation->sql, $mutation->bindings) : $this->execute($mutation->sql, $mutation->bindings);
    }

    /** Whether the primary-key row cache is enabled for this connection. */
    public function cacheEnabled(): bool
    {
        return $this->cache !== null;
    }

    /**
     * Runs `$callback` after the current transaction commits, or immediately outside a transaction.
     * Callbacks of a nested transaction that rolls back are discarded.
     */
    public function afterCommit(callable $callback): void
    {
        if (!$this->transactions->afterCommit($callback)) {
            $callback();
        }
    }

    /**
     * Invalidates cached rows changed outside the query builder (raw SQL from another process, a
     * trigger, a manual fix). Keys are single values or tuples for composite keys; `$absent` also
     * clears cached "does not exist" answers of the table. No-op without the row cache.
     *
     * @param list<list<int|string>|int|string> $keys
     */
    public function invalidate(string $table, array $keys, bool $absent = false): void
    {
        $this->cache?->invalidate($table, $keys, $absent);
    }

    /**
     * Runs a raw SQL write that changes the given rows of a cached table, keeping the row cache
     * consistent (the rows are marked while `$write` runs and refreshed when it, or the enclosing
     * transaction, ends). Use `$inserts` when the statement may create rows.
     *
     * @template T
     *
     * @param list<list<int|string>|int|string> $keys
     * @param callable(): T                      $write
     *
     * @return T
     */
    public function writing(string $table, array $keys, callable $write, bool $inserts = false): mixed
    {
        return $this->cache === null ? $write() : $this->cache->writing($table, $keys, $write, $inserts);
    }

    /**
     * Retires every cached row of the tables at once. The schema builder calls this after it
     * creates, drops, alters or renames a cached table; call it after changing a cached table in
     * another way (`TRUNCATE`, a bulk import with raw SQL, a restore).
     */
    public function flushCache(string ...$tables): void
    {
        $this->cache?->flush(...$tables);
    }

    /** @return array{hits: int, misses: int, bypassed: int, errors: int} */
    public function cacheStats(): array
    {
        return $this->cache?->stats() ?? ['hits' => 0, 'misses' => 0, 'bypassed' => 0, 'errors' => 0];
    }

    public function warmUp(): void
    {
        $this->pool->warmUp();
    }

    public function pool(): ConnectionPool
    {
        return $this->pool;
    }

    public function stats(): PoolStats
    {
        return $this->pool->stats();
    }

    public function table(string $table): Builder
    {
        return new Builder($this->driver->grammar(), $this, $table);
    }

    public function query(): Builder
    {
        return new Builder($this->driver->grammar(), $this);
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->run(static fn(PooledConnection $c): array => $c->select($sql, $bindings));
    }

    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $rows = $this->select($sql, $bindings);

        return $rows[0] ?? null;
    }

    /** @param list<mixed>|array<string, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run(static fn(PooledConnection $c): int => $c->execute($sql, $bindings));
    }

    /** @param list<mixed>|array<string, mixed> $bindings */
    public function insert(string $sql, array $bindings = []): int
    {
        return $this->run(static fn(PooledConnection $c): int => $c->insert($sql, $bindings));
    }

    /**
     * @template T
     * @param callable(PooledConnection): T $callback
     * @return T
     */
    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->transactions->transaction($this->pool, $callback, $attempts, $this->acquireTimeout());
    }

    /**
     * Leases a connection to the caller for several statements (e.g. a cursor); the caller
     * must release it. Prefer {@see transaction()} or single statements.
     */
    public function acquire(?float $timeout = null): PooledConnection
    {
        return $this->transactions->pinned() ?? $this->pool->acquire($timeout ?? $this->acquireTimeout());
    }

    /** Graceful shutdown of the pool. */
    public function close(float $drainTimeout = 10.0): int
    {
        return $this->pool->close($drainTimeout);
    }

    /**
     * @template T
     * @param callable(PooledConnection): T $fn
     * @return T
     */
    private function run(callable $fn): mixed
    {
        $pinned = $this->transactions->pinned();
        if ($pinned !== null) {
            return $fn($pinned);
        }

        return $this->pool->withConnection($fn, $this->acquireTimeout());
    }

    /** The request deadline bounds pool waits when it is sooner than the configured timeout. */
    private function acquireTimeout(): float
    {
        $timeout = $this->config->pool->acquireTimeout;
        $remaining = ContextStorage::current()?->remainingTime();
        if ($remaining !== null) {
            $timeout = max(0.001, min($timeout, $remaining));
        }

        return $timeout;
    }
}
