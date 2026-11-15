<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

/**
 * Outcome of looking up a cached query result.
 *
 * @internal
 */
final readonly class QueryLookup
{
    /**
     * @param list<array<string, mixed>>|null $rows
     * @param list<string|null>               $tokens dependency tokens observed by the lookup (null: missing)
     */
    private function __construct(public bool $hit, public ?array $rows, public bool $fillable, public array $tokens) {}

    /** @param list<array<string, mixed>> $rows */
    public static function hit(array $rows): self
    {
        return new self(true, $rows, false, []);
    }

    /** A writer is changing rows the result may contain: query the database, do not store. */
    public static function bypass(): self
    {
        return new self(false, null, false, []);
    }

    /** @param list<string|null> $tokens */
    public static function miss(array $tokens): self
    {
        return new self(false, null, true, $tokens);
    }
}
