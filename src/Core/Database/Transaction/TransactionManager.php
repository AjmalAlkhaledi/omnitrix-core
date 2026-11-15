<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Transaction;

use Closure;
use Omnitrix\Core\Database\Exception\DeadlockException;
use Omnitrix\Core\Database\Exception\TransactionException;
use Omnitrix\Core\Database\Pool\ConnectionPool;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use OpenSwoole\Coroutine;
use Throwable;

use function array_merge;
use function array_pop;
use function count;
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
 *
 * Callbacks registered with {@see afterCommit()} run once the outermost transaction committed:
 * a nested transaction's callbacks join its parent when it succeeds and are discarded when it
 * rolls back; a rolled back outer transaction (or a retried attempt) discards them all.
 *
 * Row-cache marks recorded with {@see remember()} follow the same structure: marks of a nested
 * transaction that rolls back are released at once without invalidating (the rows did not
 * change); the outermost transaction releases its marks when it ends — invalidating on commit,
 * keeping the cached data on rollback.
 */
final class TransactionManager
{
    private const string PIN_KEY = 'db.transaction.';

    private const string FRAMES_KEY = 'db.transaction.frames.';

    private const string MARKS_KEY = 'db.transaction.marks.';

    /** @var (Closure(list<string>, bool): void)|null */
    private ?Closure $onEnd;

    /**
     * @param (Closure(list<string>, bool): void)|null $onEnd receives row-cache keys to release and
     *                                                          whether the writes committed (invalidate)
     */
    public function __construct(private readonly string $connectionName, ?Closure $onEnd = null)
    {
        $this->onEnd = $onEnd;
    }

    public function inTransaction(): bool
    {
        return $this->pinned() !== null;
    }

    /**
     * Runs `$callback` after the outermost transaction of this coroutine commits. Returns false
     * (and does not keep the callback) when no transaction is active.
     */
    public function afterCommit(callable $callback): bool
    {
        $storage = Coroutine::getContext();
        $key = self::FRAMES_KEY . $this->connectionName;
        if ($storage === null || $this->pinned() === null || !isset($storage[$key])) {
            return false;
        }
        /** @var list<list<callable>> $frames */
        $frames = $storage[$key];
        $frames[count($frames) - 1][] = $callback;
        $storage[$key] = $frames;

        return true;
    }

    /**
     * Records row-cache keys marked by a write inside the transaction, to be released when the
     * outermost transaction ends. Returns false when no transaction is active.
     *
     * @param list<string> $keys
     */
    public function remember(array $keys): bool
    {
        $storage = Coroutine::getContext();
        $key = self::MARKS_KEY . $this->connectionName;
        if ($storage === null || $this->pinned() === null || !isset($storage[$key])) {
            return false;
        }
        /** @var list<list<string>> $marks one list per transaction level */
        $marks = $storage[$key];
        $top = count($marks) - 1;
        foreach ($keys as $mark) {
            $marks[$top][] = $mark;
        }
        $storage[$key] = $marks;

        return true;
    }

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
            $this->begin();
            $committed = false;
            $changed = false;
            try {
                $connection->beginTransaction();
                try {
                    $result = $callback($connection);
                    // From here a failure may hide a commit the server applied (lost connection):
                    // the row cache treats the writes as changed.
                    $changed = true;
                    $connection->commit();
                    $committed = true;

                    return $result;
                } catch (Throwable $e) {
                    $changed = $changed && $connection->isBroken(); // a clean rollback after a failed commit changed nothing
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
                [$callbacks, $marks] = $this->end();
                $this->unpin();
                $connection?->release();
                if ($marks !== [] && $this->onEnd !== null) {
                    ($this->onEnd)($marks, $committed || $changed);
                }
                if ($committed) {
                    $this->runCallbacks($callbacks);
                }
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
        $storage = Coroutine::getContext();
        $key = self::FRAMES_KEY . $this->connectionName;
        /** @var list<list<callable>> $frames */
        $frames = $storage !== null && isset($storage[$key]) ? $storage[$key] : [[]];
        $savepoint = 'omnitrix_sp_' . count($frames);
        $frames[] = [];
        $marksKey = self::MARKS_KEY . $this->connectionName;
        if ($storage !== null) {
            $storage[$key] = $frames;
            if (isset($storage[$marksKey])) {
                /** @var list<list<string>> $levels */
                $levels = $storage[$marksKey];
                $levels[] = [];
                $storage[$marksKey] = $levels;
            }
        }
        $connection->execute('SAVEPOINT ' . $savepoint);
        $released = false;
        try {
            $result = $callback($connection);
            $connection->execute('RELEASE SAVEPOINT ' . $savepoint);
            $released = true;

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
            if ($storage !== null && isset($storage[$key])) {
                /** @var list<list<callable>> $frames */
                $frames = $storage[$key];
                $own = array_pop($frames) ?? [];
                if ($released && $frames !== []) {
                    $frames[count($frames) - 1] = array_merge($frames[count($frames) - 1], $own);
                }
                $storage[$key] = $frames;
            }
            if ($storage !== null && isset($storage[$marksKey])) {
                /** @var list<list<string>> $levels */
                $levels = $storage[$marksKey];
                $ownMarks = array_pop($levels) ?? [];
                if ($released && $levels !== []) {
                    $levels[count($levels) - 1] = array_merge($levels[count($levels) - 1], $ownMarks);
                } elseif ($ownMarks !== [] && $this->onEnd !== null) {
                    ($this->onEnd)($ownMarks, false); // rolled back to the savepoint: nothing changed
                }
                $storage[$marksKey] = $levels;
            }
        }
    }

    private function begin(): void
    {
        $storage = Coroutine::getContext();
        if ($storage !== null) {
            $storage[self::FRAMES_KEY . $this->connectionName] = [[]];
            $storage[self::MARKS_KEY . $this->connectionName] = [[]];
        }
    }

    /** @return array{list<callable>, list<string>} the outer frame's callbacks and the recorded marks */
    private function end(): array
    {
        $storage = Coroutine::getContext();
        if ($storage === null) {
            return [[], []];
        }
        $framesKey = self::FRAMES_KEY . $this->connectionName;
        $marksKey = self::MARKS_KEY . $this->connectionName;
        /** @var list<list<callable>> $frames */
        $frames = $storage[$framesKey] ?? [[]];
        /** @var list<list<string>> $levels */
        $levels = $storage[$marksKey] ?? [[]];
        unset($storage[$framesKey], $storage[$marksKey]);

        return [$frames[0] ?? [], array_merge(...$levels)];
    }

    /**
     * Runs after-commit callbacks in registration order. Every callback runs; the first exception
     * is rethrown afterwards (the transaction itself is already committed).
     *
     * @param list<callable> $callbacks
     */
    private function runCallbacks(array $callbacks): void
    {
        $failure = null;
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure !== null) {
            throw $failure;
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
