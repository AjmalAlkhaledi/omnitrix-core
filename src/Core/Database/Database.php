<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database;

use Omnitrix\Core\Database\Config\DatabaseConfig;
use Omnitrix\Core\Database\Contracts\DriverInterface;
use Omnitrix\Core\Database\Pool\ConnectionPool;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Database\Pool\PoolStats;
use Omnitrix\Core\Database\Query\Builder;
use Omnitrix\Core\Database\Query\QueryExecutorInterface;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Psr\Log\LoggerInterface;

use function max;
use function min;

/**
 * One named database: its pool, grammar and transaction manager. Worker-scoped.
 *
 * Statements issued outside a transaction lease a connection for exactly one statement, so a
 * request that waits on other I/O between queries does not hold a connection meanwhile.
 * Inside {@see transaction()} every statement runs on the pinned connection.
 */
final class Database implements QueryExecutorInterface
{
    private readonly ConnectionPool $pool;

    private readonly TransactionManager $transactions;

    public function __construct(
        public readonly DatabaseConfig $config,
        private readonly DriverInterface $driver,
        ?LoggerInterface $logger = null,
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
        $this->transactions = new TransactionManager($config->name);
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
