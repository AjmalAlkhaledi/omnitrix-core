<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Contracts;

/**
 * One physical database connection. Not coroutine-safe: a connection is used by exactly one
 * coroutine at a time (the pool enforces that).
 */
interface ConnectionInterface
{
    /** Stable identifier for logs and pool bookkeeping. */
    public function id(): string;

    /**
     * Runs a statement that returns rows.
     *
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /**
     * Runs a statement without a result set; returns the affected row count.
     *
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int;

    /**
     * Runs an INSERT and returns the generated id (0 when none).
     *
     * @param list<mixed>|array<string, mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    /** Server-side statement time limit for subsequent statements (seconds, 0 = unlimited). */
    public function setStatementTimeout(float $seconds): void;

    /** Cheap liveness check (SELECT 1); false when the connection is unusable. */
    public function ping(): bool;

    /** True once the connection must not be reused (lost, interrupted, in unknown state). */
    public function isBroken(): bool;

    public function markBroken(): void;

    public function createdAt(): float;

    public function lastUsedAt(): float;

    public function touch(): void;

    public function close(): void;
}
