<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Query;

/** What the {@see Builder} needs to run compiled SQL (implemented by the connection manager). */
interface QueryExecutorInterface
{
    /**
     * @param list<mixed>|array<string, mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array;

    /** @param list<mixed>|array<string, mixed> $bindings */
    public function execute(string $sql, array $bindings = []): int;

    /** @param list<mixed>|array<string, mixed> $bindings */
    public function insert(string $sql, array $bindings = []): int;
}
