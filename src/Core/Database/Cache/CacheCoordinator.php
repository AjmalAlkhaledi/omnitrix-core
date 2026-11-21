<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Closure;
use Omnitrix\Core\Database\Contracts\GrammarInterface;
use Omnitrix\Core\Database\Transaction\TransactionManager;
use Omnitrix\Core\Runtime\Coroutine\Timers;
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
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function ksort;
use function max;
use function microtime;
use function preg_match;
use function rtrim;
use function serialize;
use function sha1;
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

    /** @var array<string, array<string, string>> "table.column" => value => collation weight */
    private array $weights = [];

    /** @var array<string, true> keys whose invalidation is owed after a Redis failure */
    private array $debt = [];

    /** @var array<string, int> marks held by running writes in this worker => count */
    private array $active = [];

    private ?int $renewal = null;

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
        $keyColumns = $this->schema->isCached($table) && !$this->transactions->inTransaction() && $this->payDebt() ? $this->schema->keyColumns($table) : null;
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
        $marks = array_merge($marks, $this->triggerMarks($mutation->table, self::events($mutation)));

        return $this->guarded($marks, fn(): int => $this->runAll($statements, $mutation->kind));
    }

    /**
     * Marks `$marks`, runs `$write`, and ends the marks: inside a transaction when the outermost
     * transaction ends (see {@see release()}); otherwise right after the write. A failed write
     * outside a transaction still invalidates, because a lost connection can hide a committed
     * statement.
     *
     * @template T
     *
     * @param list<string>   $marks
     * @param callable(): T  $write
     *
     * @return T
     */
    private function guarded(array $marks, callable $write): mixed
    {
        if ($marks === []) {
            return $write();
        }
        $this->mark($marks);
        if ($this->transactions->remember($marks)) {
            return $write();
        }
        try {
            return $write();
        } finally {
            $this->end($marks, true);
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
        $marks = array_merge($marks, $this->triggerMarks($table, ['INSERT', 'UPDATE', 'DELETE']));

        return $this->guarded($marks, $write);
    }

    /**
     * Generations of the cached tables that triggers on `$table` change: marked for the write
     * like rows, so readers bypass those tables until it ends and a commit retires all their
     * entries.
     *
     * @param list<'INSERT'|'UPDATE'|'DELETE'> $events
     *
     * @return list<string>
     */
    private function triggerMarks(string $table, array $events): array
    {
        return array_map(fn(string $target): string => $this->cache->generationKey($target), $this->schema->triggerTargets($table, $events));
    }

    /**
     * Trigger events a statement can fire: an upsert also updates, REPLACE also deletes.
     *
     * @return list<'INSERT'|'UPDATE'|'DELETE'>
     */
    private static function events(Mutation $mutation): array
    {
        if (!$mutation->isInsert()) {
            return [$mutation->kind === Mutation::DELETE ? 'DELETE' : 'UPDATE'];
        }
        $events = ['INSERT'];
        if (preg_match('/\bon\s+duplicate\s+key\s+update\b|\bon\s+conflict\b.*\bdo\s+update\b/is', $mutation->sql) === 1) {
            $events[] = 'UPDATE';
        }
        if (preg_match('/^\s*replace\b/i', $mutation->sql) === 1) {
            $events[] = 'DELETE';
        }

        return $events;
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
        $this->schema->reset(); // keys, columns and foreign keys may have changed
        foreach ($tables as $table) {
            if ($this->schema->isConfigured($table)) {
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
        if ($this->schema->trackedColumns($mutation->table) !== null) {
            return true; // the old values of tracked columns must be read
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
        $table = $mutation->table;
        $marks = [];
        $keyColumns = $this->schema->keyColumns($table);
        if ($keyColumns !== null) {
            $marks[] = $this->cache->epochKey($table);
            foreach (self::tuples($mutation->insertedRows, $keyColumns) as $tuple) {
                $marks[] = $this->cache->versionKey($table, $tuple);
            }
        }
        $tracked = $this->schema->trackedColumns($table);
        if ($tracked !== null) {
            foreach ($tracked as $column => $info) {
                $values = [];
                $unknown = false;
                foreach ($mutation->insertedRows as $row) {
                    if (!array_key_exists($column, $row)) {
                        $unknown = true; // the database default applies; its value is not known here
                    } else {
                        $values[] = $row[$column];
                    }
                }
                $marks = array_merge($marks, $this->tagMarks($table, $column, $info, $values));
                if ($unknown) {
                    $marks[] = $this->cache->columnKey($table, $column);
                }
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
        $tracked = $this->schema->trackedColumns($table) ?? [];
        $rowCached = $this->schema->keyColumns($table) !== null;
        $keyColumns = $rowCached || $tracked !== [] ? $this->schema->primaryKey($table) : null;
        $tuples = null;
        if ($keyColumns !== null) {
            $order = $mutation->keyLookup === null ? null : self::reorder($mutation->keyLookup['columns'], $keyColumns);
            if ($order !== null && $mutation->keyLookup !== null && $tracked === []) {
                $tuples = array_map(static fn(array $key): array => self::arrange($key, $order), $mutation->keyLookup['keys']);
            } else {
                // Lock the affected rows and read their keys (and the old values of tracked columns).
                $columns = $keyColumns;
                foreach (array_keys($tracked) as $column) {
                    if (!in_array($column, $columns, true)) {
                        $columns[] = $column;
                    }
                }
                $rows = $this->selectFor($mutation, $columns);
                $tuples = self::unique(self::tuples($rows, $keyColumns));
                if ($tuples === []) {
                    return [[], []];
                }
                if ($mutation->byKeys !== null && $order === null) {
                    $statements = [];
                    foreach (array_chunk($tuples, self::CHUNK) as $chunk) {
                        $statements[] = ($mutation->byKeys)($keyColumns, $chunk);
                    }
                }
                foreach ($tracked as $column => $info) {
                    $old = array_map(static fn(array $row): mixed => $row[$column] ?? null, $rows);
                    $marks = array_merge($marks, $this->tagMarks($table, $column, $info, $old));
                    if ($mutation->kind === Mutation::UPDATE && array_key_exists($column, $mutation->values)) {
                        $new = $mutation->values[$column];
                        $marks = array_merge($marks, is_int($new) || is_string($new) || $new === null
                            ? $this->tagMarks($table, $column, $info, [$new])
                            : [$this->cache->columnKey($table, $column)]); // computed value (e.g. increment)
                    }
                }
            }
            if ($rowCached) {
                $marks = array_merge($marks, $this->rowMarks($table, $tuples));
                if (array_intersect($mutation->updatedColumns, $keyColumns) !== []) {
                    $marks[] = $this->cache->epochKey($table); // a changed key may name a row cached as absent
                }
            }
        } elseif ($tracked !== []) {
            // No primary key: the statement keeps its WHERE; the locked rows give the old values.
            $rows = $this->selectFor($mutation, array_keys($tracked));
            foreach ($tracked as $column => $info) {
                $marks = array_merge($marks, $this->tagMarks($table, $column, $info, array_map(static fn(array $row): mixed => $row[$column] ?? null, $rows)));
                if ($mutation->kind === Mutation::UPDATE && array_key_exists($column, $mutation->values)) {
                    $new = $mutation->values[$column];
                    $marks = array_merge($marks, is_int($new) || is_string($new) || $new === null
                        ? $this->tagMarks($table, $column, $info, [$new])
                        : [$this->cache->columnKey($table, $column)]);
                }
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
        $delete = $mutation->kind === Mutation::DELETE;
        foreach ($cascades as $cascade) {
            // Values of the referenced parent columns for the affected parent rows.
            if ($keyColumns !== null && $tuples !== null && $keyColumns === $cascade->parentColumns) {
                $values = $tuples;
            } else {
                $values = self::tuples($this->selectFor($mutation, $cascade->parentColumns), $cascade->parentColumns);
            }
            $marks = array_merge($marks, $this->childMarks($cascade, self::unique($values), $delete, $depth));
        }

        return $marks;
    }

    /**
     * Marks the rows of `$cascade->childTable` that reference `$values` (tuples of the referenced
     * parent columns) — cached rows and query tags of their tracked columns — and recursively the
     * rows their own deletion cascades into.
     *
     * @param list<list<int|string>> $values
     *
     * @return list<string>
     */
    private function childMarks(Cascade $cascade, array $values, bool $delete, int $depth): array
    {
        $child = $cascade->childTable;
        $childKeys = $this->schema->keyColumns($child);
        $tracked = $this->schema->trackedColumns($child) ?? [];
        if ($values === [] || $depth >= self::MAX_CASCADE_DEPTH) {
            return [];
        }
        $columns = $childKeys ?? [];
        // Next level: rows deleted by this cascade cascade their deletion; columns this cascade
        // changes (ON UPDATE CASCADE, SET NULL) cascade as updates of those columns.
        $grandchildren = [];
        foreach ($this->schema->cascadesFrom($child) as $next) {
            $deletes = $delete && $next->onDelete;
            $updates = $next->onUpdate && array_intersect($next->parentColumns, $cascade->childColumns) !== [];
            if ($deletes || $updates) {
                $grandchildren[] = [$next, $deletes];
            }
        }
        foreach ([...array_keys($tracked), ...array_merge([], ...array_map(static fn(array $g): array => $g[0]->parentColumns, $grandchildren))] as $column) {
            if (!in_array($column, $columns, true)) {
                $columns[] = $column;
            }
        }
        if ($columns === []) {
            return [];
        }
        $rows = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $sql = 'select ' . implode(', ', array_map(fn(string $c): string => $this->grammar->wrap($c), $columns))
                . ' from ' . $this->grammar->wrapTable($child)
                . ' where ' . $this->keyCondition($cascade->childColumns, count($chunk)) . ' for update';
            $rows = array_merge($rows, ($this->select)($sql, self::flatten($chunk)));
        }
        $marks = [];
        if ($childKeys !== null) {
            $marks = $this->rowMarks($child, self::tuples($rows, $childKeys));
        }
        foreach ($tracked as $column => $info) {
            $marks = array_merge($marks, $this->tagMarks($child, $column, $info, array_map(static fn(array $row): mixed => $row[$column] ?? null, $rows)));
            if (!$delete && in_array($column, $cascade->childColumns, true)) {
                $marks[] = $this->cache->columnKey($child, $column); // the cascaded new value is not read here
            }
        }
        foreach ($grandchildren as [$next, $deletes]) {
            $marks = array_merge($marks, $this->childMarks($next, self::unique(self::tuples($rows, $next->parentColumns)), $deletes, $depth + 1));
        }

        return $marks;
    }

    // --- query tags -------------------------------------------------------------------------

    /**
     * @param list<mixed>                $predicates
     * @param list<mixed>                $bindings
     * @param Closure(): list<array<string, mixed>> $fallback
     *
     * @return list<array<string, mixed>>
     */
    public function selectCached(string $table, string $sql, array $bindings, array $predicates, int $ttl, Closure $fallback): array
    {
        if ($this->transactions->inTransaction() || !$this->payDebt()) {
            return $fallback();
        }
        $tracked = $this->schema->trackedColumns($table);
        if ($tracked === null) {
            return $fallback();
        }
        $best = null;
        foreach ($predicates as $predicate) {
            if (!is_array($predicate) || !is_string($predicate['column'] ?? null) || !is_array($predicate['values'] ?? null) || !isset($tracked[$predicate['column']])) {
                continue;
            }
            if ($predicate['values'] !== [] && ($best === null || count($predicate['values']) < count($best['values']))) {
                $best = ['column' => $predicate['column'], 'values' => array_values($predicate['values'])];
            }
        }
        if ($best === null) {
            return $fallback();
        }
        $column = $best['column'];
        try {
            $tags = $this->tagValues($table, $column, $tracked[$column], $best['values'], strict: true);
        } catch (Throwable $e) {
            $this->failed('tags', $e);

            return $fallback();
        }
        if ($tags === null) {
            return $fallback(); // a value the column cannot hold (e.g. 'abc' for an integer column)
        }
        $dependencies = [$this->cache->generationKey($table), $this->cache->columnKey($table, $column)];
        foreach ($tags as $tag) {
            $dependencies[] = $this->cache->tagKey($table, $column, $tag);
        }
        $key = $this->cache->queryKey($table, sha1(serialize([$sql, $bindings])));
        try {
            $lookup = $this->cache->lookupQuery($key, $dependencies);
        } catch (Throwable $e) {
            $this->failed('lookup', $e);

            return $fallback();
        }
        if ($lookup->hit && $lookup->rows !== null) {
            $this->stats['hits']++;

            return $lookup->rows;
        }
        $lookup->fillable ? $this->stats['misses']++ : $this->stats['bypassed']++;
        $rows = $fallback();
        try {
            $this->cache->fillQuery($key, $dependencies, $lookup, $rows, $ttl);
        } catch (Throwable $e) {
            $this->failed('fill', $e);
        }

        return $rows;
    }

    /**
     * Tag version keys for rows whose `$column` holds one of `$values`.
     *
     * @param array{kind: 'int'|'string', charset: string, collation: string, padded: bool, exact: bool} $info
     * @param list<mixed>                                                      $values
     *
     * @return list<string>
     */
    private function tagMarks(string $table, string $column, array $info, array $values): array
    {
        $tags = $this->tagValues($table, $column, $info, $values, strict: false);
        if ($tags === null) {
            return [$this->cache->columnKey($table, $column)];
        }

        return array_map(fn(string $tag): string => $this->cache->tagKey($table, $column, $tag), $tags);
    }

    /**
     * Canonical tags of values as the column compares them: integers by value, text by the
     * column's collation weight (trailing spaces ignored). NULL values get no tag (no query
     * matches them with `=`). Returns null when a value cannot be tagged.
     *
     * @param array{kind: 'int'|'string', charset: string, collation: string, padded: bool, exact: bool} $info
     * @param list<mixed>                                                      $values
     *
     * @return list<string>|null
     */
    private function tagValues(string $table, string $column, array $info, array $values, bool $strict): ?array
    {
        $tags = [];
        if ($info['kind'] === 'int') {
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }
                if (!is_int($value) && (!is_string($value) || preg_match('/^-?\d+$/', $value) !== 1)) {
                    return null;
                }
                $tags[(string) (int) $value] = true;
            }

            return array_map(static fn(int|string $t): string => (string) $t, array_keys($tags));
        }
        if ($this->grammar->dialect() === 'pgsql') {
            // Deterministic collations compare text byte by byte: the bytes are the tag (CHAR(n)
            // ignores trailing spaces). Non-deterministic ones (ICU, case-insensitive) cannot be
            // tagged; the column-wide key covers them.
            if (!$info['exact']) {
                return null;
            }
            foreach ($values as $value) {
                if ($value === null) {
                    continue;
                }
                if (!is_scalar($value)) {
                    return null;
                }
                $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                $tags[bin2hex($info['padded'] ? rtrim($text, ' ') : $text)] = true;
            }

            return array_map(static fn(int|string $t): string => (string) $t, array_keys($tags));
        }
        $pending = [];
        $memo = $table . '.' . $column;
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            if (!is_scalar($value)) {
                return null;
            }
            $text = rtrim(is_bool($value) ? ($value ? '1' : '0') : (string) $value, ' ');
            if (isset($this->weights[$memo][$text])) {
                $tags[$this->weights[$memo][$text]] = true;
            } else {
                $pending[$text] = true;
            }
        }
        if ($pending !== []) {
            if (preg_match('/^[A-Za-z0-9_]+$/', $info['charset'] . $info['collation']) !== 1) {
                return null;
            }
            if (count($this->weights[$memo] ?? []) > 10000) {
                $this->weights[$memo] = [];
            }
            foreach (array_chunk(array_map(static fn(int|string $t): string => (string) $t, array_keys($pending)), 200) as $chunk) {
                $parts = [];
                foreach (array_keys($chunk) as $i) {
                    $parts[] = "hex(weight_string(convert(? using {$info['charset']}) collate {$info['collation']})) as w{$i}";
                }
                $row = ($this->select)('select ' . implode(', ', $parts), $chunk)[0] ?? [];
                foreach ($chunk as $i => $text) {
                    $weight = $row["w{$i}"] ?? null;
                    if (!is_string($weight)) {
                        return null;
                    }
                    $this->weights[$memo][$text] = $weight;
                    $tags[$weight] = true;
                }
            }
        }

        return array_map(static fn(int|string $t): string => (string) $t, array_keys($tags));
    }

    /**
     * `col IN (?, …)` for one column, `(a = ? and b = ?) or …` for several.
     *
     * @param list<string> $columns
     */
    private function keyCondition(array $columns, int $count): string
    {
        if (count($columns) === 1) {
            return $this->grammar->wrap($columns[0]) . ' in (' . implode(', ', array_fill(0, $count, '?')) . ')';
        }
        $tuple = '(' . implode(' and ', array_map(fn(string $c): string => $this->grammar->wrap($c) . ' = ?', $columns)) . ')';

        return implode(' or ', array_fill(0, $count, $tuple));
    }

    /**
     * Key tuples of the rows, skipping rows with a NULL in any of the columns.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $columns
     *
     * @return list<list<int|string>>
     */
    private static function tuples(array $rows, array $columns): array
    {
        $tuples = [];
        foreach ($rows as $row) {
            $tuple = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    continue 2;
                }
                $tuple[] = $value;
            }
            $tuples[] = $tuple;
        }

        return $tuples;
    }

    /**
     * @param list<list<int|string>> $tuples
     *
     * @return list<int|string>
     */
    private static function flatten(array $tuples): array
    {
        $flat = [];
        foreach ($tuples as $tuple) {
            foreach ($tuple as $value) {
                $flat[] = $value;
            }
        }

        return $flat;
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
            if ($delete ? $cascade->onDelete : ($cascade->onUpdate && array_intersect($cascade->parentColumns, $updatedColumns) !== [])) {
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

    /**
     * Marks keys before a write. In strict mode (the default) a failure aborts the write. Otherwise
     * the write proceeds and the keys become a debt of this worker, paid (invalidated) as soon as
     * Redis answers again; until then this worker does not use the cache.
     *
     * @param list<string> $marks
     */
    private function mark(array $marks): void
    {
        $this->payDebt();
        try {
            foreach (array_chunk($marks, self::CHUNK) as $chunk) {
                $this->cache->mark($chunk);
            }
        } catch (Throwable $e) {
            $this->failed('mark', $e);
            if ($this->cache->config->strict) {
                throw new CacheUnavailableException('The row cache could not be updated before a write; the write was not run (strict mode)', 0, $e);
            }
            $this->owe($marks);

            return;
        }
        $this->renew($marks, +1);
    }

    /**
     * Ends marks: `$changed` installs fresh versions (the write committed or may have); otherwise
     * the marks are released and cached data kept (the write rolled back).
     *
     * @param list<string> $marks
     */
    private function end(array $marks, bool $changed): void
    {
        $this->renew($marks, -1);
        try {
            foreach (array_chunk($marks, self::CHUNK) as $chunk) {
                $changed ? $this->cache->publish($chunk) : $this->cache->release($chunk);
            }
        } catch (Throwable $e) {
            $this->failed($changed ? 'publish' : 'release', $e);
            if ($changed) {
                // Marks that were set stay until lock_ttl (readers use the database meanwhile);
                // invalidate as soon as Redis answers so nothing filled later survives.
                $this->owe($marks);
            }
        }
    }

    /**
     * Ends marks recorded by a transaction (called by the transaction manager): on commit (or a
     * commit with an unknown outcome) the rows are invalidated; on rollback the marks are released
     * and the cached rows kept.
     *
     * @param list<string> $marks
     */
    public function release(array $marks, bool $committed = true): void
    {
        $this->end($marks, $committed);
    }

    /** @param list<string> $keys */
    private function owe(array $keys): void
    {
        foreach ($keys as $key) {
            $this->debt[$key] = true;
        }
    }

    /** Invalidates keys whose writes could not be marked; true when nothing is owed. */
    private function payDebt(): bool
    {
        if ($this->debt === []) {
            return true;
        }
        try {
            foreach (array_chunk(array_keys($this->debt), self::CHUNK) as $chunk) {
                $this->cache->invalidate($chunk);
            }
            $this->debt = [];

            return true;
        } catch (Throwable $e) {
            $this->failed('invalidate', $e);

            return false;
        }
    }

    /**
     * Keeps the marks of running writes alive: one worker-wide timer extends every active mark
     * by `lock_ttl` every third of `lock_ttl`, so a transaction longer than `lock_ttl` never loses
     * its marks.
     *
     * @param list<string> $marks
     */
    private function renew(array $marks, int $delta): void
    {
        foreach ($marks as $key) {
            $count = ($this->active[$key] ?? 0) + $delta;
            if ($count > 0) {
                $this->active[$key] = $count;
            } else {
                unset($this->active[$key]);
            }
        }
        if ($this->active !== [] && $this->renewal === null) {
            $interval = max(200, intdiv($this->cache->config->lockTtl * 1000, 3));
            $this->renewal = Timers::tick($interval, function (): void {
                if ($this->active === []) {
                    if ($this->renewal !== null) {
                        Timers::clear($this->renewal);
                        $this->renewal = null;
                    }

                    return;
                }
                try {
                    foreach (array_chunk(array_keys($this->active), self::CHUNK) as $chunk) {
                        $this->cache->extend($chunk);
                    }
                } catch (Throwable $e) {
                    $this->failed('extend', $e);
                }
            });
        }
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
            foreach (($this->select)('select * from ' . $this->grammar->wrapTable($table) . ' where ' . $this->keyCondition($keyColumns, count($chunk)), self::flatten($chunk)) as $row) {
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
