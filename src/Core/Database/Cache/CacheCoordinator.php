<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Closure;
use Omnitrix\Core\Database\Contracts\GrammarInterface;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_chunk;
use function array_fill;
use function array_flip;
use function array_intersect;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_int;
use function is_scalar;
use function is_string;
use function microtime;
use function sort;

/**
 * Keeps the primary-key row cache consistent with the database.
 *
 * Reads: a plain lookup by key on a cached table is served from {@see RowCache}; misses are read
 * from the database with one query and stored. Reads inside a transaction always use the
 * database, because they must see the transaction's own uncommitted writes.
 *
 * Writes (every insert, update and delete built by the query builder):
 *
 * 1. find the affected rows: from the WHERE clause when it names rows by key, otherwise with
 *    `SELECT key … FOR UPDATE` inside a transaction (the statement then runs by key, so exactly the
 *    locked rows change); rows changed by `ON DELETE/UPDATE CASCADE | SET NULL` foreign keys in
 *    cached tables are found the same way; inserts mark the table's insert epoch;
 * 2. mark those rows in the cache before the statement runs (readers bypass the cache for them);
 * 3. run the statement;
 * 4. publish fresh versions after the outermost transaction ends (or right after the statement
 *    outside a transaction).
 *
 * Only cached tables (and tables whose foreign keys cascade into them) take part; writes to other
 * tables run unchanged.
 *
 * @internal
 */
final class CacheCoordinator
{
    private const int CHUNK = 1000;

    private const int MAX_CASCADE_DEPTH = 8;

    /** @var array{hits: int, misses: int, bypassed: int, errors: int} */
    private array $stats = ['hits' => 0, 'misses' => 0, 'bypassed' => 0, 'errors' => 0];

    private float $lastErrorLog = 0.0;

    /**
     * @param Closure(string, list<mixed>): list<array<string, mixed>> $select  runs a query (on the pinned connection inside transactions)
     * @param Closure(string, list<mixed>): int                         $execute runs a statement
     * @param Closure(string, list<mixed>): int                         $insert  runs an insert and returns the generated id
     * @param Closure(callable(): mixed): mixed                         $transaction runs a callback in a transaction
     */
    public function __construct(
        private readonly RowCache $cache,
        private readonly CacheSchema $schema,
        private readonly TransactionManager $transactions,
        private readonly GrammarInterface $grammar,
        private readonly Closure $select,
        private readonly Closure $execute,
        private readonly Closure $insert,
        private readonly Closure $transaction,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function involves(string $table): bool
    {
        return $this->schema->involves($table);
    }

    /** @return array{hits: int, misses: int, bypassed: int, errors: int} */
    public function stats(): array
    {
        return $this->stats;
    }

    // --- reads ------------------------------------------------------------------------------

    /**
     * @param list<string>                          $columns
     * @param list<list<int|string>>                $keys
     * @param Closure(): list<array<string, mixed>> $fallback
     *
     * @return list<array<string, mixed>>
     */
    public function selectByKeys(string $table, array $columns, array $keys, Closure $fallback): array
    {
        $keyColumns = $this->schema->isCached($table) && !$this->transactions->inTransaction() ? $this->schema->keyColumns($table) : null;
        $order = $keyColumns === null ? null : self::reorder($columns, $keyColumns);
        if ($keyColumns === null || $order === null) {
            return $fallback();
        }
        $tuples = self::unique(array_map(static fn(array $key): array => self::arrange($key, $order), $keys));
        try {
            $lookups = $this->cache->lookup($table, $tuples);
        } catch (Throwable $e) {
            $this->failed('lookup', $e);

            return $fallback();
        }
        $rows = [];
        $missing = [];
        foreach ($tuples as $i => $tuple) {
            $lookup = $lookups[$i];
            if ($lookup->hit) {
                $this->stats['hits']++;
                if ($lookup->row !== null) {
                    $rows[$i] = $lookup->row;
                }
            } else {
                $lookup->fillable ? $this->stats['misses']++ : $this->stats['bypassed']++;
                $missing[$i] = $tuple;
            }
        }
        if ($missing !== []) {
            $loaded = $this->loadByKeys($table, $keyColumns, array_values($missing));
            $fills = [];
            foreach ($missing as $i => $tuple) {
                $row = $loaded[self::fingerprint($tuple)] ?? null;
                if ($row !== null) {
                    $rows[$i] = $row;
                }
                $fills[] = ['key' => $tuple, 'row' => $row, 'lookup' => $lookups[$i]];
            }
            try {
                $this->cache->fill($table, $fills);
            } catch (Throwable $e) {
                $this->failed('fill', $e);
            }
        }
        ksort($rows);

        return array_values($rows);
    }

    // --- writes -----------------------------------------------------------------------------

    public function mutate(Mutation $mutation): int
    {
        if (!$this->schema->involves($mutation->table)) {
            return $this->run($mutation->sql, $mutation->bindings, $mutation->kind);
        }
        if (!$this->transactions->inTransaction() && $this->needsSelect($mutation)) {
            // Finding the affected rows needs locking reads; run the whole write atomically.
            $result = ($this->transaction)(fn(): int => $this->mutate($mutation));

            return is_int($result) ? $result : 0;
        }

        $marks = [];
        $statements = [[$mutation->sql, $mutation->bindings]];
        if ($mutation->isInsert()) {
            $marks = $this->insertMarks($mutation);
        } else {
            [$marks, $statements] = $this->changeMarks($mutation);
        }
        if ($statements === []) {
            return 0; // nothing matched the WHERE clause
        }

        $this->mark($marks);
        if ($this->transactions->remember($marks)) {
            return $this->runAll($statements, $mutation->kind);
        }
        try {
            return $this->runAll($statements, $mutation->kind);
        } finally {
            $this->publish($marks);
        }
    }

    /**
     * Rows changed by a statement the builder did not produce (raw SQL): marks them for the
     * duration of `$write` and publishes fresh versions when it (or the enclosing transaction)
     * ends. `$inserts` also covers rows that may not have existed before.
     *
     * @template T
     *
     * @param list<list<int|string>|int|string> $keys
     * @param callable(): T                      $write
     *
     * @return T
     */
    public function writing(string $table, array $keys, callable $write, bool $inserts = false): mixed
    {
        $marks = $this->rowMarks($table, $keys);
        if ($inserts && $this->schema->isCached($table)) {
            $marks[] = $this->cache->epochKey($table);
        }
        $this->mark($marks);
        if ($this->transactions->remember($marks)) {
            return $write();
        }
        try {
            return $write();
        } finally {
            $this->publish($marks);
        }
    }

    /**
     * Invalidates cached rows changed outside the application (another service, a trigger, a
     * manual fix). `$absent` also invalidates cached "does not exist" answers for the table.
     *
     * @param list<list<int|string>|int|string> $keys
     */
    public function invalidate(string $table, array $keys, bool $absent = false): void
    {
        $versions = $this->rowMarks($table, $keys);
        if ($absent && $this->schema->isCached($table)) {
            $versions[] = $this->cache->epochKey($table);
        }
        foreach (array_chunk($versions, self::CHUNK) as $chunk) {
            $this->cache->invalidate($chunk);
        }
    }

    /**
     * Retires every cached entry of the tables (after they were created, dropped, altered,
     * renamed or truncated).
     */
    public function flush(string ...$tables): void
    {
        foreach ($tables as $table) {
            if ($this->schema->isCached($table)) {
                try {
                    $this->cache->flush($table);
                } catch (Throwable $e) {
                    $this->failed('flush', $e);
                }
            }
        }
    }

    private function needsSelect(Mutation $mutation): bool
    {
        if ($mutation->isInsert()) {
            return false;
        }
        $cached = $this->schema->keyColumns($mutation->table);
        if ($cached !== null && ($mutation->keyLookup === null || self::reorder($mutation->keyLookup['columns'], $cached) === null)) {
            return true;
        }

        return $this->relevantCascades($mutation->table, $mutation->kind === Mutation::DELETE, $mutation->updatedColumns) !== [];
    }

    /** @return list<string> */
    private function insertMarks(Mutation $mutation): array
    {
        $keyColumns = $this->schema->keyColumns($mutation->table);
        if ($keyColumns === null) {
            return [];
        }
        $marks = [$this->cache->epochKey($mutation->table)];
        foreach ($mutation->insertedRows as $row) {
            $tuple = [];
            foreach ($keyColumns as $column) {
                $value = $row[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    $tuple = null;
                    break;
                }
                $tuple[] = $value;
            }
            if ($tuple !== null) {
                $marks[] = $this->cache->versionKey($mutation->table, $tuple);
            }
        }

        return $marks;
    }

    /**
     * Version keys to mark for an update or delete, and the statements to run (rewritten by key
     * when the rows had to be selected first).
     *
     * @return array{list<string>, list<array{string, list<mixed>}>}
     */
    private function changeMarks(Mutation $mutation): array
    {
        $table = $mutation->table;
        $statements = [[$mutation->sql, $mutation->bindings]];
        $marks = [];
        $keyColumns = $this->schema->keyColumns($table);
        $tuples = null;
        if ($keyColumns !== null) {
            $order = $mutation->keyLookup === null ? null : self::reorder($mutation->keyLookup['columns'], $keyColumns);
            if ($order !== null && $mutation->keyLookup !== null) {
                $tuples = array_map(static fn(array $key): array => self::arrange($key, $order), $mutation->keyLookup['keys']);
            } else {
                $tuples = self::unique(array_map(static fn(array $row): array => self::tuple($row, $keyColumns), $this->selectFor($mutation, $keyColumns)));
                if ($tuples === []) {
                    return [[], []];
                }
                if ($mutation->byKeys !== null) {
                    $statements = [];
                    foreach (array_chunk($tuples, self::CHUNK) as $chunk) {
                        $statements[] = ($mutation->byKeys)($keyColumns, $chunk);
                    }
                }
            }
            $marks = $this->rowMarks($table, $tuples);
            if (array_intersect($mutation->updatedColumns, $keyColumns) !== []) {
                $marks[] = $this->cache->epochKey($table); // a changed key may name a row cached as absent
            }
        }

        $cascades = $this->relevantCascades($table, $mutation->kind === Mutation::DELETE, $mutation->updatedColumns);
        if ($cascades !== []) {
            $marks = array_merge($marks, $this->cascadeMarks($mutation, $keyColumns, $tuples, $cascades, 0));
        }

        return [self::uniqueStrings($marks), $statements];
    }

    /**
     * @param list<string>|null           $keyColumns
     * @param list<list<int|string>>|null $tuples
     * @param list<Cascade>               $cascades
     *
     * @return list<string>
     */
    private function cascadeMarks(Mutation $mutation, ?array $keyColumns, ?array $tuples, array $cascades, int $depth): array
    {
        $marks = [];
        foreach ($cascades as $cascade) {
            // Values of the referenced parent column for the affected parent rows.
            if ($keyColumns !== null && $tuples !== null && $keyColumns === [$cascade->parentColumn]) {
                $values = array_map(static fn(array $t): int|string => $t[0], $tuples);
            } else {
                $values = [];
                foreach ($this->selectFor($mutation, [$cascade->parentColumn]) as $row) {
                    $value = $row[$cascade->parentColumn] ?? null;
                    if (is_int($value) || is_string($value)) {
                        $values[] = $value;
                    }
                }
            }
            $marks = array_merge($marks, $this->childMarks($cascade, self::uniqueScalars($values), $depth));
        }

        return $marks;
    }

    /**
     * Marks the rows of `$cascade->childTable` that reference `$values`, and recursively the rows
     * their own deletion cascades into.
     *
     * @param list<int|string> $values
     *
     * @return list<string>
     */
    private function childMarks(Cascade $cascade, array $values, int $depth): array
    {
        $childKeys = $this->schema->keyColumns($cascade->childTable);
        if ($values === [] || $depth >= self::MAX_CASCADE_DEPTH) {
            return [];
        }
        $columns = $childKeys ?? [];
        $grandchildren = $cascade->onDelete ? $this->schema->cascadesFrom($cascade->childTable) : [];
        foreach ($grandchildren as $next) {
            if (!in_array($next->parentColumn, $columns, true)) {
                $columns[] = $next->parentColumn;
            }
        }
        if ($columns === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $sql = 'select ' . implode(', ', array_map(fn(string $c): string => $this->grammar->wrap($c), $columns))
                . ' from ' . $this->grammar->wrapTable($cascade->childTable)
                . ' where ' . $this->grammar->wrap($cascade->childColumn) . ' in (' . implode(', ', array_fill(0, count($chunk), '?')) . ') for update';
            $rows = array_merge($rows, ($this->select)($sql, $chunk));
        }
        $marks = [];
        if ($childKeys !== null) {
            $marks = $this->rowMarks($cascade->childTable, array_map(static fn(array $row): array => self::tuple($row, $childKeys), $rows));
        }
        foreach ($grandchildren as $next) {
            $nextValues = [];
            foreach ($rows as $row) {
                $value = $row[$next->parentColumn] ?? null;
                if (is_int($value) || is_string($value)) {
                    $nextValues[] = $value;
                }
            }
            $marks = array_merge($marks, $this->childMarks($next, self::uniqueScalars($nextValues), $depth + 1));
        }

        return $marks;
    }

    /**
     * @param list<string> $updatedColumns
     *
     * @return list<Cascade>
     */
    private function relevantCascades(string $table, bool $delete, array $updatedColumns): array
    {
        $relevant = [];
        foreach ($this->schema->cascadesFrom($table) as $cascade) {
            if ($delete ? $cascade->onDelete : ($cascade->onUpdate && in_array($cascade->parentColumn, $updatedColumns, true))) {
                $relevant[] = $cascade;
            }
        }

        return $relevant;
    }

    /**
     * @param list<string> $columns
     *
     * @return list<array<string, mixed>>
     */
    private function selectFor(Mutation $mutation, array $columns): array
    {
        if ($mutation->selectKeys === null) {
            return [];
        }
        [$sql, $bindings] = ($mutation->selectKeys)($columns);

        return ($this->select)($sql, $bindings);
    }

    /**
     * @param list<list<int|string>|int|string> $keys
     *
     * @return list<string>
     */
    private function rowMarks(string $table, array $keys): array
    {
        if (!$this->schema->isCached($table)) {
            return [];
        }
        $marks = [];
        foreach ($keys as $key) {
            $marks[] = $this->cache->versionKey($table, is_int($key) || is_string($key) ? [$key] : array_values($key));
        }

        return $marks;
    }

    /** @param list<string> $marks */
    private function mark(array $marks): void
    {
        try {
            foreach (array_chunk($marks, self::CHUNK) as $chunk) {
                $this->cache->mark($chunk);
            }
        } catch (Throwable $e) {
            $this->failed('mark', $e);
            if ($this->cache->config->strict) {
                throw new CacheUnavailableException('The row cache could not be updated before a write; the write was not run (strict mode)', 0, $e);
            }
        }
    }

    /** @param list<string> $marks */
    private function publish(array $marks): void
    {
        try {
            foreach (array_chunk($marks, self::CHUNK) as $chunk) {
                $this->cache->publish($chunk);
            }
        } catch (Throwable $e) {
            $this->failed('publish', $e);
        }
    }

    /**
     * Releases marks recorded by a transaction (called by the transaction manager).
     *
     * @param list<string> $marks
     */
    public function release(array $marks): void
    {
        $this->publish(self::uniqueStrings($marks));
    }

    /** @param list<array{string, list<mixed>}> $statements */
    private function runAll(array $statements, string $kind): int
    {
        $total = 0;
        foreach ($statements as [$sql, $bindings]) {
            $total += $this->run($sql, $bindings, $kind);
        }

        return $total;
    }

    /** @param list<mixed> $bindings */
    private function run(string $sql, array $bindings, string $kind): int
    {
        return $kind === Mutation::INSERT_GET_ID ? ($this->insert)($sql, $bindings) : ($this->execute)($sql, $bindings);
    }

    /**
     * @param list<string>           $keyColumns
     * @param list<list<int|string>> $tuples
     *
     * @return array<string, array<string, mixed>> fingerprint => row
     */
    private function loadByKeys(string $table, array $keyColumns, array $tuples): array
    {
        $rows = [];
        foreach (array_chunk($tuples, self::CHUNK) as $chunk) {
            if (count($keyColumns) === 1) {
                $condition = $this->grammar->wrap($keyColumns[0]) . ' in (' . implode(', ', array_fill(0, count($chunk), '?')) . ')';
            } else {
                $tuple = '(' . implode(' and ', array_map(fn(string $c): string => $this->grammar->wrap($c) . ' = ?', $keyColumns)) . ')';
                $condition = implode(' or ', array_fill(0, count($chunk), $tuple));
            }
            $bindings = [];
            foreach ($chunk as $key) {
                foreach ($key as $value) {
                    $bindings[] = $value;
                }
            }
            foreach (($this->select)('select * from ' . $this->grammar->wrapTable($table) . ' where ' . $condition, $bindings) as $row) {
                $rows[self::fingerprint(self::tuple($row, $keyColumns))] = $row;
            }
        }

        return $rows;
    }

    private function failed(string $operation, Throwable $e): void
    {
        $this->stats['errors']++;
        $now = microtime(true);
        if ($this->logger !== null && $now - $this->lastErrorLog >= 10.0) {
            $this->lastErrorLog = $now;
            $this->logger->warning('db.cache.' . $operation . ' failed; using the database', ['error' => $e->getMessage(), 'class' => $e::class]);
        }
    }

    /**
     * Position of each table key column in the given lookup columns, or null when they differ.
     *
     * @param list<string> $columns
     * @param list<string> $keyColumns
     *
     * @return list<int>|null
     */
    private static function reorder(array $columns, array $keyColumns): ?array
    {
        if (count($columns) !== count($keyColumns)) {
            return null;
        }
        $positions = array_flip($columns);
        $order = [];
        foreach ($keyColumns as $column) {
            if (!array_key_exists($column, $positions)) {
                return null;
            }
            $order[] = $positions[$column];
        }

        return $order;
    }

    /**
     * @param list<int|string> $key
     * @param list<int>        $order
     *
     * @return list<int|string>
     */
    private static function arrange(array $key, array $order): array
    {
        return array_map(static fn(int $i): int|string => $key[$i], $order);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     *
     * @return list<int|string>
     */
    private static function tuple(array $row, array $columns): array
    {
        return array_map(static function (string $c) use ($row): int|string {
            $value = $row[$c] ?? null;

            return is_int($value) || is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
        }, $columns);
    }

    /** @param list<int|string> $tuple */
    private static function fingerprint(array $tuple): string
    {
        return implode("\0", array_map(static fn(int|string $v): string => (string) $v, $tuple));
    }

    /**
     * @param list<list<int|string>> $tuples
     *
     * @return list<list<int|string>>
     */
    private static function unique(array $tuples): array
    {
        $unique = [];
        foreach ($tuples as $tuple) {
            $unique[self::fingerprint($tuple)] = $tuple;
        }

        return array_values($unique);
    }

    /**
     * @param list<int|string> $values
     *
     * @return list<int|string>
     */
    private static function uniqueScalars(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $unique[(string) $value] = $value;
        }

        return array_values($unique);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function uniqueStrings(array $values): array
    {
        $unique = array_keys(array_flip($values));
        sort($unique);

        return $unique;
    }
}
