<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Contracts;

/** SQL dialect specifics used by the query builder. */
interface GrammarInterface
{
    /** Quotes an identifier such as `users` or `u.name` (`*` is passed through). */
    public function wrap(string $identifier): string;

    /** Quotes a table name, optionally with an alias ("users as u"). */
    public function wrapTable(string $table): string;

    /** Placeholder for one bound value. */
    public function parameter(): string;

    public function compileLimitOffset(?int $limit, ?int $offset): string;

    public function supportsReturning(): bool;

    /** Dialect name: `mysql` (MySQL, MariaDB) or `pgsql`. */
    public function dialect(): string;

    /** Whether UPDATE and DELETE accept ORDER BY and LIMIT (MySQL); otherwise the builder limits by row id. */
    public function supportsLimitedWrites(): bool;

    /** Insert statement that yields the generated key (`RETURNING` where supported). */
    public function compileInsertGetId(string $insert, string $key): string;
}
