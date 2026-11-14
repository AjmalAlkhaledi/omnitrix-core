<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Pool;

use Closure;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\CancelledException;
use Omnitrix\Core\Database\Config\PoolConfig;
use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\PoolExhaustedException;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Throwable;

use function count;
use function hrtime;
use function max;
use function microtime;
use function round;
use function spl_object_id;

/**
 * Bounded pool of physical connections for one worker process.
 *
 * - Idle connections wait in a Channel (FIFO); `acquire()` pops one or, below `max`, creates
 *   one; otherwise it waits up to the acquisition timeout → {@see PoolExhaustedException}.
 * - Checkout validates with a ping when the connection was idle longer than
 *   `validation_interval`; broken connections are destroyed and replaced.
 * - Release returns healthy connections; broken ones and connections still in a transaction
 *   are destroyed (a transaction left open is a bug upstream and must never be reused).
 * - A timer evicts idle connections beyond `idle_timeout` and recycles connections older than
 *   `max_lifetime`, keeping at least `min` connections.
 * - `close()` stops creation, waits for leases to come back (bounded), then closes everything.
 */
final class ConnectionPool
{
    private readonly Channel $idle;

    private int $size = 0;

    private int $inUse = 0;

    private int $waiting = 0;

    private int $created = 0;

    private int $destroyed = 0;

    private int $acquired = 0;

    private int $timeouts = 0;

    private int $validationFailures = 0;

    private float $totalWait = 0.0;

    private float $maxWait = 0.0;

    private bool $closed = false;

    private ?int $evictionTimer = null;

    /** @var array<int, ConnectionInterface> every live connection (idle or leased), by object id */
    private array $live = [];

    /**
     * @param Closure(): ConnectionInterface $factory creates a physical connection
     * @param null|Closure(string, array<string, mixed>): void $log optional diagnostics sink
     */
    public function __construct(
        public readonly string $name,
        private readonly PoolConfig $config,
        private readonly Closure $factory,
        private readonly ?Closure $log = null,
    ) {
        $this->idle = new Channel(max(1, $config->max));
    }

    /** Opens `min` connections eagerly and starts the eviction timer (call from the worker). */
    public function warmUp(): void
    {
        for ($i = $this->size; $i < $this->config->min; $i++) {
            try {
                $this->idle->push($this->create());
            } catch (ConnectionException $e) {
                $this->emit('warmup_failed', ['error' => $e->getMessage()]);
                break; // the database may not be up yet; connections are created lazily later
            }
        }
        $this->startEviction();
    }

    /**
     * Leases a connection to the current coroutine.
     *
     * @throws PoolExhaustedException when no connection is available within the timeout
     * @throws ConnectionException    when a new connection cannot be established
     * @throws CancelledException     when the token is cancelled while waiting
     */
    public function acquire(?float $timeout = null, ?CancellationToken $token = null): PooledConnection
    {
        if ($this->closed) {
            throw new ConnectionException("Pool '{$this->name}' is closed");
        }
        $token?->throwIfCancelled();
        $timeout ??= $this->config->acquireTimeout;
        $start = hrtime(true);
        $deadline = $start + (int) ($timeout * 1e9);

        while (true) {
            // Channel timeouts below 1 ms are rounded to zero and become indefinite waits, so idle
            // connections are taken only when the channel is known to be non-empty.
            $connection = $this->idle->isEmpty() ? false : $this->idle->pop(1.0);
            if (!$connection instanceof ConnectionInterface) {
                if ($this->size < $this->config->max) {
                    $this->size++; // reserve the slot before the (suspending) connect
                    try {
                        $connection = $this->create(reserved: true);
                    } catch (Throwable $e) {
                        $this->size--;
                        throw $e;
                    }
                } else {
                    $connection = $this->waitForIdle($deadline, $token);
                }
            }

            if (!$this->validate($connection)) {
                $this->destroy($connection);
                if (hrtime(true) > $deadline) {
                    $this->timeouts++;
                    throw new PoolExhaustedException("Pool '{$this->name}': no healthy connection within {$timeout}s");
                }
                continue;
            }

            $this->inUse++;
            $this->acquired++;
            $waited = (hrtime(true) - $start) / 1e9;
            $this->totalWait += $waited;
            $this->maxWait = max($this->maxWait, $waited);
            $connection->touch();

            return new PooledConnection($connection, $this, Coroutine::getCid());
        }
    }

    /**
     * Runs `$fn` with a leased connection and releases it afterwards (also on exceptions).
     *
     * @template T
     * @param callable(PooledConnection): T $fn
     * @return T
     */
    public function withConnection(callable $fn, ?float $timeout = null, ?CancellationToken $token = null): mixed
    {
        $connection = $this->acquire($timeout, $token);
        try {
            return $fn($connection);
        } finally {
            $connection->release();
        }
    }

    /** @internal called by {@see PooledConnection::release()} */
    public function giveBack(ConnectionInterface $connection): void
    {
        $this->inUse--;
        if ($this->closed || $connection->isBroken() || $connection->inTransaction()) {
            if ($connection->inTransaction() && !$connection->isBroken()) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    // unusable anyway
                }
                $this->emit('released_in_transaction', ['connection' => $connection->id()]);
            }
            $this->destroy($connection);

            return;
        }
        $connection->touch();
        if ($this->idle->isFull() || !$this->idle->push($connection, 1.0)) {
            $this->destroy($connection); // cannot happen while size <= max, but never leak
        }
    }

    public function stats(): PoolStats
    {
        return new PoolStats($this->size, $this->idle->length(), $this->inUse, $this->waiting, $this->created, $this->destroyed, $this->acquired, $this->timeouts, $this->validationFailures, round($this->totalWait, 6), round($this->maxWait, 6));
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    /** Evicts idle connections past idle_timeout / max_lifetime, keeping `min`. */
    public function evict(): int
    {
        $now = microtime(true);
        $evicted = 0;
        $keep = [];
        while (!$this->idle->isEmpty() && ($connection = $this->idle->pop(1.0)) instanceof ConnectionInterface) {
            $idleFor = $now - $connection->lastUsedAt();
            $age = $now - $connection->createdAt();
            $tooIdle = $this->config->idleTimeout > 0.0 && $idleFor > $this->config->idleTimeout && $this->size > $this->config->min;
            $tooOld = $this->config->maxLifetime > 0.0 && $age > $this->config->maxLifetime;
            if ($tooIdle || $tooOld || $connection->isBroken()) {
                $this->destroy($connection);
                $evicted++;
                continue;
            }
            $keep[] = $connection;
        }
        foreach ($keep as $connection) {
            $this->idle->push($connection);
        }
        if ($this->size < $this->config->min && !$this->closed) {
            try {
                $this->idle->push($this->create());
            } catch (ConnectionException) {
                // retried at the next eviction tick
            }
        }

        return $evicted;
    }

    /**
     * Graceful shutdown: refuses new leases, waits up to `$drainTimeout` for leased
     * connections to return, then closes everything.
     *
     * @return int connections that were still leased when the wait ended
     */
    public function close(float $drainTimeout = 10.0): int
    {
        $this->closed = true;
        if ($this->evictionTimer !== null) {
            Timers::clear($this->evictionTimer);
            $this->evictionTimer = null;
        }
        if (Coroutine::getCid() < 1) {
            // WorkerStop runs outside any coroutine, so channel operations are
            // unavailable, so close every tracked connection directly. Leases can no longer return.
            $leased = $this->inUse;
            foreach ($this->live as $connection) {
                try {
                    $connection->close();
                } catch (Throwable) {
                    // closing a dead socket may fail
                }
            }
            $this->destroyed += count($this->live);
            $this->live = [];
            $this->size = 0;

            return $leased;
        }
        $deadline = hrtime(true) + (int) ($drainTimeout * 1e9);
        while ($this->inUse > 0 && hrtime(true) < $deadline) {
            Coroutine::usleep(10_000);
        }
        while (!$this->idle->isEmpty() && ($connection = $this->idle->pop(1.0)) instanceof ConnectionInterface) {
            $this->destroy($connection);
        }
        $this->idle->close();

        return $this->inUse;
    }

    private function waitForIdle(int $deadline, ?CancellationToken $token): ConnectionInterface
    {
        $remaining = ($deadline - hrtime(true)) / 1e9;
        if ($remaining <= 0.0) {
            $this->timeouts++;
            throw new PoolExhaustedException("Pool '{$this->name}' exhausted ({$this->config->max} connections in use)");
        }
        $cid = Coroutine::getCid();
        $token?->attach($cid);
        $this->waiting++;
        try {
            $connection = $this->idle->pop($remaining);
        } finally {
            $this->waiting--;
            $token?->detach($cid);
        }
        if ($connection instanceof ConnectionInterface) {
            return $connection;
        }
        if (Coroutine::isCanceled() || ($token !== null && $token->isCancelled())) {
            throw new CancelledException('Cancelled while waiting for a database connection');
        }
        $this->timeouts++;
        throw new PoolExhaustedException("Pool '{$this->name}' exhausted: {$this->config->max} connections in use, none released within {$this->config->acquireTimeout}s");
    }

    private function validate(ConnectionInterface $connection): bool
    {
        if ($connection->isBroken()) {
            $this->validationFailures++;

            return false;
        }
        $idleFor = microtime(true) - $connection->lastUsedAt();
        if ($this->config->validationInterval > 0.0 && $idleFor < $this->config->validationInterval) {
            return true;
        }
        if ($connection->ping()) {
            return true;
        }
        $this->validationFailures++;
        $this->emit('validation_failed', ['connection' => $connection->id()]);

        return false;
    }

    private function create(bool $reserved = false): ConnectionInterface
    {
        $connection = ($this->factory)();
        $this->live[spl_object_id($connection)] = $connection;
        if (!$reserved) {
            $this->size++;
        }
        $this->created++;
        $this->emit('created', ['connection' => $connection->id(), 'size' => $this->size]);

        return $connection;
    }

    private function destroy(ConnectionInterface $connection): void
    {
        unset($this->live[spl_object_id($connection)]);
        $this->size--;
        $this->destroyed++;
        try {
            $connection->close();
        } catch (Throwable) {
            // closing a broken socket may fail; nothing to recover
        }
        $this->emit('destroyed', ['connection' => $connection->id(), 'size' => $this->size]);
    }

    private function startEviction(): void
    {
        if ($this->evictionTimer !== null || $this->closed) {
            return;
        }
        $this->evictionTimer = Timers::tick(max(100, (int) round($this->config->evictionInterval * 1000)), function (): void {
            if (!$this->closed) {
                $this->evict();
            }
        });
    }

    /** @param array<string, mixed> $context */
    private function emit(string $event, array $context): void
    {
        if ($this->log !== null) {
            ($this->log)($event, $context + ['pool' => $this->name]);
        }
    }
}
