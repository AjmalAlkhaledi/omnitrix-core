<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Pool;

use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Exception\OwnershipViolationException;
use OpenSwoole\Coroutine;

/**
 * A connection checked out of the pool, bound to the coroutine that acquired it.
 *
 * Every call verifies the caller is the owning coroutine and the lease is still active:
 * sharing a connection between coroutines or using it after `release()` is a programming
 * error that would otherwise corrupt the protocol (verified fatal in OpenSwoole).
 */
final class PooledConnection implements ConnectionInterface
{
    private bool $released = false;

    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly ConnectionPool $pool,
        private readonly int $ownerCid,
    ) {}

    public function ownerCid(): int
    {
        return $this->ownerCid;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }

    /** Returns the connection to the pool (or discards it when broken / mid-transaction). */
    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        $this->pool->giveBack($this->inner);
    }

    /** The underlying physical connection (for diagnostics; do not retain). */
    public function inner(): ConnectionInterface
    {
        return $this->inner;
    }

    public function id(): string
    {
        return $this->inner->id();
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->assertOwner();

        return $this->inner->select($sql, $bindings);
    }

    public function execute(string $sql, array $bindings = []): int
    {
        $this->assertOwner();

        return $this->inner->execute($sql, $bindings);
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->assertOwner();

        return $this->inner->insert($sql, $bindings);
    }

    public function beginTransaction(): void
    {
        $this->assertOwner();
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->assertOwner();
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->assertOwner();
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function setStatementTimeout(float $seconds): void
    {
        $this->assertOwner();
        $this->inner->setStatementTimeout($seconds);
    }

    public function ping(): bool
    {
        $this->assertOwner();

        return $this->inner->ping();
    }

    public function isBroken(): bool
    {
        return $this->inner->isBroken();
    }

    public function markBroken(): void
    {
        $this->inner->markBroken();
    }

    public function createdAt(): float
    {
        return $this->inner->createdAt();
    }

    public function lastUsedAt(): float
    {
        return $this->inner->lastUsedAt();
    }

    public function touch(): void
    {
        $this->inner->touch();
    }

    /** Closing a leased connection discards it from the pool. */
    public function close(): void
    {
        $this->inner->markBroken();
        $this->release();
    }

    private function assertOwner(): void
    {
        if ($this->released) {
            throw new OwnershipViolationException("Connection {$this->inner->id()} was already released to the pool");
        }
        $cid = Coroutine::getCid();
        if ($cid !== $this->ownerCid) {
            throw new OwnershipViolationException("Connection {$this->inner->id()} belongs to coroutine #{$this->ownerCid}, used from #{$cid}");
        }
    }
}
