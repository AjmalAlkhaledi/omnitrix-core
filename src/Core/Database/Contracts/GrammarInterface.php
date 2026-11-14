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
}
