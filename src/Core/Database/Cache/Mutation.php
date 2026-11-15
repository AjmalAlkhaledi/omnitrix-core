<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Closure;

/**
 * A write statement built by the query builder, with what the row cache needs to find the rows
 * it changes.
 *
 * @internal created by {@see \Omnitrix\Core\Database\Query\Builder}
 */
final readonly class Mutation
{
    public const string INSERT = 'insert';

    public const string INSERT_GET_ID = 'insertGetId';

    public const string UPDATE = 'update';

    public const string DELETE = 'delete';

    /**
     * @param self::* $kind
     * @param list<mixed> $bindings
     * @param array{columns: list<string>, keys: list<list<int|string>>}|null $keyLookup
     *     the rows are named by their key in the WHERE clause (`pk = ?`, `pk IN (…)`, `a = ? AND b = ?`);
     *     the statement changes at most these rows
     * @param list<string> $updatedColumns columns assigned by an update
     * @param array<string, mixed> $values assignments of an update (column => value or Expression)
     * @param list<array<string, mixed>> $insertedRows rows of an insert
     * @param (Closure(list<string>): array{string, list<mixed>})|null $selectKeys
     *     builds `SELECT columns … FOR UPDATE` with the statement's WHERE, ORDER BY and LIMIT
     * @param (Closure(list<string>, list<list<int|string>>): array{string, list<mixed>})|null $byKeys
     *     builds the same statement restricted to the given key tuples (without ORDER BY/LIMIT)
     */
    public function __construct(
        public string $table,
        public string $kind,
        public string $sql,
        public array $bindings,
        public ?array $keyLookup = null,
        public array $updatedColumns = [],
        public array $insertedRows = [],
        public ?Closure $selectKeys = null,
        public ?Closure $byKeys = null,
        public array $values = [],
    ) {}

    public function isInsert(): bool
    {
        return $this->kind === self::INSERT || $this->kind === self::INSERT_GET_ID;
    }
}
