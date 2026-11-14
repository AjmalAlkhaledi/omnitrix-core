<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Transaction;

use Omnitrix\Core\Database\Exception\DeadlockException;
use Omnitrix\Core\Database\Exception\TransactionException;
use Omnitrix\Core\Database\Pool\ConnectionPool;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use OpenSwoole\Coroutine;
use Throwable;

use function max;
use function min;
use function random_int;

/**
 * Transactions with exclusive connection ownership.
 *
 * `transaction()` leases a connection, pins it to the current coroutine (so every statement
 * issued through the manager inside the callback runs on that connection), begins,
 * runs the callback, commits, and releases. Any throwable rolls back and is rethrown.
 * Deadlocks (`DeadlockException`) are retried up to `$attempts` with a short jittered backoff.
 * Nested calls reuse the pinned connection through savepoints.
 */
final class TransactionManager
{
    private const string PIN_KEY = 'db.transaction.';

    private int $depth = 0;

    public function __construct(private readonly string $connectionName) {}

    /**
     * @template T
     * @param callable(PooledConnection): T $callback
     * @return T
     */
    public function transaction(ConnectionPool $pool, callable $callback, int $attempts = 1, ?float $acquireTimeout = null): mixed
    {
        if ($attempts < 1) {
            throw new TransactionException('attempts must be >= 1');
        }
        $pinned = $this->pinned();
        if ($pinned !== null) {
            return $this->nested($pinned, $callback);
        }

        for ($attempt = 1; ; $attempt++) {
            $connection = $pool->acquire($acquireTimeout);
            $this->pin($connection);
            try {
                $connection->beginTransaction();
                try {
                    $result = $callback($connection);
                    $connection->commit();

                    return $result;
                } catch (Throwable $e) {
                    try {
                        if ($connection->inTransaction() && !$connection->isBroken()) {
                            $connection->rollBack();
                        }
                    } catch (Throwable) {
                        $connection->markBroken();
                    }
                    if ($e instanceof DeadlockException && $attempt < $attempts) {
                        $this->unpin();
                        $connection->release();
                        $connection = null;
                        Sleep::milliseconds(random_int(5, 20) * min($attempt, 10));
                        continue;
                    }
                    throw $e;
                }
            } finally {
                $this->unpin();
                $connection?->release();
            }
        }
    }

    /**
     * The connection pinned by an enclosing transaction in the *current coroutine*, if any.
     * The pin lives in the coroutine's own storage (not the inherited request context): child
     * tasks started inside a transaction run on their own connections, because a single
     * connection cannot serve two coroutines at once.
     */
    public function pinned(): ?PooledConnection
    {
        $storage = Coroutine::getContext();
        if ($storage === null) {
            return null;
        }
        $pinned = $storage[self::PIN_KEY . $this->connectionName] ?? null;

        return $pinned instanceof PooledConnection && !$pinned->isReleased() ? $pinned : null;
    }

    /**
     * @template T
     * @param callable(PooledConnection): T $callback
     * @return T
     */
    private function nested(PooledConnection $connection, callable $callback): mixed
    {
        $this->depth++;
        $savepoint = 'omnitrix_sp_' . max(1, $this->depth);
        $connection->execute('SAVEPOINT ' . $savepoint);
        try {
            $result = $callback($connection);
            $connection->execute('RELEASE SAVEPOINT ' . $savepoint);

            return $result;
        } catch (Throwable $e) {
            if (!$connection->isBroken()) {
                try {
                    $connection->execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                } catch (Throwable) {
                    $connection->markBroken();
                }
            }
            throw $e;
        } finally {
            $this->depth--;
        }
    }

    private function pin(PooledConnection $connection): void
    {
        $storage = Coroutine::getContext();
        if ($storage === null) {
            throw new TransactionException('Transactions require a coroutine context');
        }
        $storage[self::PIN_KEY . $this->connectionName] = $connection;
    }

    private function unpin(): void
    {
        $storage = Coroutine::getContext();
        if ($storage !== null) {
            unset($storage[self::PIN_KEY . $this->connectionName]);
        }
    }
}
