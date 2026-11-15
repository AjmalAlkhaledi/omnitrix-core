<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Query;

use Closure;
use Omnitrix\Core\Database\Cache\Mutation;

/**
 * An executor with a primary-key row cache: the {@see Builder} routes primary-key lookups and
 * every write through it, so cached rows are served and invalidated without application code.
 *
 * @internal implemented by {@see \Omnitrix\Core\Database\Database}
 */
interface CacheAwareExecutorInterface extends QueryExecutorInterface
{
    /**
     * Rows of `$table` whose key columns `$columns` equal one of `$keys`, served from the cache
     * where possible. `$fallback` runs the original query when the cache does not apply.
     *
     * @param list<string>                                  $columns
     * @param list<list<int|string>>                        $keys
     * @param Closure(): list<array<string, mixed>>         $fallback
     *
     * @return list<array<string, mixed>>
     */
    public function selectByKeys(string $table, array $columns, array $keys, Closure $fallback): array;

    /** Runs a write; returns the affected row count, or the generated id for insertGetId. */
    public function mutate(Mutation $mutation): int;
}
