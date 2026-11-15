<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Query;

use Closure;
use InvalidArgumentException;
use Omnitrix\Core\Database\Cache\Mutation;
use Omnitrix\Core\Database\Contracts\GrammarInterface;

use function array_fill;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function func_num_args;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Fluent SQL builder with positional bindings. No hydration, no model layer: results are plain
 * arrays straight from the driver. All identifiers are quoted through the grammar; all values
 * become bound parameters. Raw fragments must be wrapped in {@see Expression} explicitly.
 */
final class Builder
{
    private const array OPERATORS = ['=', '<', '>', '<=', '>=', '<>', '!=', '<=>', 'like', 'not like', 'in', 'not in', 'is', 'is not', 'between', 'not between', 'regexp', 'not regexp'];

    /** @var list<string|Expression> */
    private array $columns = ['*'];

    private bool $distinct = false;

    private ?string $from = null;

    /** @var list<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<WhereClause> */
    private array $wheres = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<array{column: string|Expression, operator: string, value: mixed, boolean: string}> */
    private array $havings = [];

    /** @var list<array{column: string|Expression, direction: string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $lockForUpdate = false;

    private ?int $cacheTtl = null;

    /** @var array<string, list<mixed>> */
    private array $bindings = ['select' => [], 'join' => [], 'where' => [], 'having' => []];

    public function __construct(private readonly GrammarInterface $grammar, private readonly QueryExecutorInterface $executor, ?string $table = null)
    {
        if ($table !== null) {
            $this->from = $table;
        }
    }

    /** @param list<mixed> $bindings */
    public static function raw(string $sql, array $bindings = []): Expression
    {
        return new Expression($sql, $bindings);
    }

    // --- select -----------------------------------------------------------------------------

    public function from(string $table): self
    {
        $this->from = $table;

        return $this;
    }

    /** @param string|Expression ...$columns */
    public function select(string|Expression ...$columns): self
    {
        $this->columns = $columns === [] ? ['*'] : array_values($columns);
        foreach ($columns as $column) {
            if ($column instanceof Expression) {
                $this->bindings['select'] = array_merge($this->bindings['select'], $column->bindings);
            }
        }

        return $this;
    }

    public function addSelect(string|Expression ...$columns): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        foreach ($columns as $column) {
            $this->columns[] = $column;
            if ($column instanceof Expression) {
                $this->bindings['select'] = array_merge($this->bindings['select'], $column->bindings);
            }
        }

        return $this;
    }

    public function distinct(): self
    {
        $this->distinct = true;

        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second, string $type = 'inner'): self
    {
        $this->joins[] = ['type' => $type, 'table' => $table, 'first' => $first, 'operator' => $this->operator($operator), 'second' => $second];

        return $this;
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'right');
    }

    // --- where ------------------------------------------------------------------------------

    /**
     * `where('id', 10)`, `where('age', '>=', 18)`, `where(fn (Builder $q) => $q->where(...)->orWhere(...))`,
     * `where(['a' => 1, 'b' => 2])`.
     *
     * @param string|Expression|Closure(Builder): void|array<string, mixed> $column
     */
    public function where(string|Expression|Closure|array $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if (is_array($column)) {
            foreach ($column as $name => $val) {
                $this->where($name, '=', $val, $boolean);
            }

            return $this;
        }
        if ($column instanceof Closure) {
            $nested = new self($this->grammar, $this->executor, $this->from);
            $column($nested);
            if ($nested->wheres !== []) {
                $this->wheres[] = new WhereClause('nested', $boolean, nested: $nested);
                $this->bindings['where'] = array_merge($this->bindings['where'], $nested->bindings['where']);
            }

            return $this;
        }
        if ($column instanceof Expression) {
            $this->wheres[] = new WhereClause('raw', $boolean, count: count($column->bindings), sql: $column->sql);
            $this->bindings['where'] = array_merge($this->bindings['where'], $column->bindings);

            return $this;
        }

        if ($value === null && func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        if (!is_string($operator)) {
            throw new InvalidArgumentException('Operator must be a string');
        }
        $operator = $this->operator($operator);
        if ($value === null && in_array($operator, ['=', 'is'], true)) {
            return $this->whereNull($column, $boolean);
        }
        if ($value === null && in_array($operator, ['<>', '!=', 'is not'], true)) {
            return $this->whereNull($column, $boolean, true);
        }
        if (in_array($operator, ['in', 'not in'], true)) {
            if (!is_array($value)) {
                throw new InvalidArgumentException('IN requires an array of values');
            }

            return $this->whereIn($column, array_values($value), $boolean, $operator === 'not in');
        }
        $this->wheres[] = new WhereClause('basic', $boolean, $column, $operator);
        $this->bindings['where'][] = $value;

        return $this;
    }

    /** @param string|Expression|Closure(Builder): void|array<string, mixed> $column */
    public function orWhere(string|Expression|Closure|array $column, mixed $operator = null, mixed $value = null): self
    {
        return func_num_args() === 2 ? $this->where($column, '=', $operator, 'or') : $this->where($column, $operator, $value, 'or');
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        if ($values === []) {
            $this->wheres[] = new WhereClause('raw', $boolean, sql: $not ? '1 = 1' : '0 = 1');

            return $this;
        }
        $this->wheres[] = new WhereClause('in', $boolean, $column, not: $not, count: count($values));
        $this->bindings['where'] = array_merge($this->bindings['where'], array_values($values));

        return $this;
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function whereNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = new WhereClause('null', $boolean, $column, not: $not);

        return $this;
    }

    public function whereNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function whereBetween(string $column, mixed $from, mixed $to, string $boolean = 'and', bool $not = false): self
    {
        $this->wheres[] = new WhereClause('between', $boolean, $column, not: $not);
        $this->bindings['where'][] = $from;
        $this->bindings['where'][] = $to;

        return $this;
    }

    public function whereColumn(string $first, string $operator, string $second, string $boolean = 'and'): self
    {
        $this->wheres[] = new WhereClause('column', $boolean, operator: $this->operator($operator), first: $first, second: $second);

        return $this;
    }

    /** @param list<mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = [], string $boolean = 'and'): self
    {
        return $this->where(new Expression($sql, $bindings), null, null, $boolean);
    }

    // --- grouping / ordering / limits ---------------------------------------------------

    public function groupBy(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function having(string|Expression $column, string $operator, mixed $value, string $boolean = 'and'): self
    {
        $this->havings[] = ['column' => $column, 'operator' => $this->operator($operator), 'value' => $value, 'boolean' => $boolean];
        $this->bindings['having'][] = $value;

        return $this;
    }

    public function orderBy(string|Expression $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Order direction must be asc or desc, got '{$direction}'");
        }
        $this->orders[] = ['column' => $column, 'direction' => $direction];

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidArgumentException('limit must be >= 0');
        }
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidArgumentException('offset must be >= 0');
        }
        $this->offset = $offset;

        return $this;
    }

    public function lockForUpdate(): self
    {
        $this->lockForUpdate = true;

        return $this;
    }

    // --- compile ----------------------------------------------------------------------------

    public function toSql(): string
    {
        $sql = 'select ' . ($this->distinct ? 'distinct ' : '') . $this->compileColumns();
        $sql .= ' from ' . $this->grammar->wrapTable($this->table());
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join['type'] . ' join ' . $this->grammar->wrapTable($join['table']) . ' on ' . $this->grammar->wrap($join['first']) . ' ' . $join['operator'] . ' ' . $this->grammar->wrap($join['second']);
        }
        $sql .= $this->compileWheres();
        if ($this->groups !== []) {
            $sql .= ' group by ' . implode(', ', array_map(fn(string $c): string => $this->grammar->wrap($c), $this->groups));
        }
        if ($this->havings !== []) {
            $parts = [];
            foreach ($this->havings as $i => $having) {
                $parts[] = ($i === 0 ? '' : ' ' . $having['boolean'] . ' ') . $this->wrapColumn($having['column']) . ' ' . $having['operator'] . ' ?';
            }
            $sql .= ' having ' . implode('', $parts);
        }
        if ($this->orders !== []) {
            $sql .= ' order by ' . implode(', ', array_map(fn(array $o): string => $this->wrapColumn($o['column']) . ' ' . $o['direction'], $this->orders));
        }
        $sql .= $this->grammar->compileLimitOffset($this->limit, $this->offset);
        if ($this->lockForUpdate) {
            $sql .= ' for update';
        }

        return $sql;
    }

    /** @return list<mixed> */
    public function getBindings(): array
    {
        return array_merge($this->bindings['select'], $this->bindings['join'], $this->bindings['where'], $this->bindings['having']);
    }

    // --- execute ----------------------------------------------------------------------------

    /**
     * Caches the result of this query for `$seconds` when the connection tracks one of the columns
     * it filters on with `=` or `IN` (the `cache.queries` option). The entry is invalidated when a
     * row with a matching value is written — before or after the write — not on every write to the
     * table. Queries that cannot be tracked run normally.
     */
    public function cache(int $seconds): self
    {
        if ($seconds < 1) {
            throw new InvalidArgumentException('cache() needs a lifetime of at least one second');
        }
        $this->cacheTtl = $seconds;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function get(): array
    {
        if ($this->executor instanceof CacheAwareExecutorInterface && $this->from !== null) {
            $lookup = $this->readKeyLookup();
            if ($lookup !== null) {
                $executor = $this->executor;

                return $executor->selectByKeys($this->from, $lookup['columns'], $lookup['keys'], fn(): array => $executor->select($this->toSql(), $this->getBindings()));
            }
        }

        return $this->run($this->toSql(), $this->getBindings());
    }

    /**
     * Top-level `col = ?` and `col IN (…)` conditions when every top-level condition is joined
     * with AND and the query reads only this table; null when the query cannot be tracked.
     *
     * @internal used by the query cache
     *
     * @return list<array{column: string, values: list<mixed>}>|null
     */
    public function cacheDependencies(): ?array
    {
        if ($this->from === null || $this->joins !== []) {
            return null;
        }
        $predicates = [];
        $offset = 0;
        foreach ($this->wheres as $i => $where) {
            if ($i > 0 && $where->boolean !== 'and') {
                return null;
            }
            $count = $this->bindingCount($where);
            $column = $this->keyColumn($where->column);
            if ($column !== null && $where->type === 'basic' && $where->operator === '=') {
                $predicates[] = ['column' => $column, 'values' => [$this->bindings['where'][$offset] ?? null]];
            } elseif ($column !== null && $where->type === 'in' && !$where->not) {
                $predicates[] = ['column' => $column, 'values' => array_slice($this->bindings['where'], $offset, $where->count)];
            }
            $offset += $count;
        }

        return $predicates;
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return list<array<string, mixed>>
     */
    private function run(string $sql, array $bindings): array
    {
        if ($this->cacheTtl !== null && $this->from !== null && $this->executor instanceof CacheAwareExecutorInterface) {
            $predicates = $this->cacheDependencies();
            if ($predicates !== null && $predicates !== []) {
                $executor = $this->executor;

                return $executor->selectCached($this->from, $sql, $bindings, $predicates, $this->cacheTtl, static fn(): array => $executor->select($sql, $bindings));
            }
        }

        return $this->executor->select($sql, $bindings);
    }

    private function bindingCount(WhereClause $where): int
    {
        return match ($where->type) {
            'basic' => 1,
            'in' => $where->count,
            'between' => 2,
            'raw' => $where->count,
            'nested' => $where->nested === null ? 0 : count($where->nested->bindings['where']),
            default => 0,
        };
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $previous = $this->limit;
        $this->limit = 1;
        try {
            $rows = $this->get();
        } finally {
            $this->limit = $previous;
        }

        return $rows[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function find(int|string $id, string $column = 'id'): ?array
    {
        return $this->where($column, '=', $id)->first();
    }

    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();

        return $row === null ? null : ($row[array_key_first($row) ?? ''] ?? null);
    }

    /** @return list<mixed> */
    public function pluck(string $column): array
    {
        $values = [];
        foreach ($this->select($column)->get() as $row) {
            $values[] = $row[array_key_first($row) ?? ''] ?? null;
        }

        return $values;
    }

    public function count(string $column = '*'): int
    {
        $row = $this->aggregate('count', $column);

        return is_int($row) ? $row : (int) (is_string($row) ? $row : 0);
    }

    public function exists(): bool
    {
        $sql = 'select exists(' . $this->toSql() . ') as `exists`';
        $rows = $this->run($sql, $this->getBindings());
        $value = $rows[0]['exists'] ?? 0;

        return (int) (is_int($value) || is_string($value) ? $value : 0) === 1;
    }

    public function sum(string $column): int|float
    {
        $v = $this->aggregate('sum', $column);

        return is_int($v) || is_float($v) ? $v : (float) (is_string($v) ? $v : 0);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('max', $column);
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('min', $column);
    }

    public function avg(string $column): ?float
    {
        $v = $this->aggregate('avg', $column);

        return $v === null ? null : (float) (is_int($v) || is_float($v) || is_string($v) ? $v : 0);
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $values */
    public function insert(array $values): int
    {
        $rows = $this->normaliseRows($values);
        [$sql, $bindings] = $this->compileInsert($rows);
        if ($this->executor instanceof CacheAwareExecutorInterface) {
            return $this->executor->mutate(new Mutation($this->table(), Mutation::INSERT, $sql, $bindings, insertedRows: $rows));
        }

        return $this->executor->execute($sql, $bindings);
    }

    /** @param array<string, mixed> $values */
    public function insertGetId(array $values): int
    {
        $rows = $this->normaliseRows($values);
        [$sql, $bindings] = $this->compileInsert($rows);
        if ($this->executor instanceof CacheAwareExecutorInterface) {
            return $this->executor->mutate(new Mutation($this->table(), Mutation::INSERT_GET_ID, $sql, $bindings, insertedRows: $rows));
        }

        return $this->executor->insert($sql, $bindings);
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): int
    {
        if ($values === []) {
            throw new InvalidArgumentException('update() requires at least one column');
        }
        $sets = [];
        $bindings = [];
        foreach ($values as $column => $value) {
            if ($value instanceof Expression) {
                $sets[] = $this->grammar->wrap($column) . ' = ' . $value->sql;
                $bindings = array_merge($bindings, $value->bindings);
            } else {
                $sets[] = $this->grammar->wrap($column) . ' = ?';
                $bindings[] = $value;
            }
        }
        $head = 'update ' . $this->grammar->wrapTable($this->table()) . ' set ' . implode(', ', $sets);
        $sql = $head . $this->compileWheres();
        $sql .= $this->orders !== [] ? ' order by ' . implode(', ', array_map(fn(array $o): string => $this->wrapColumn($o['column']) . ' ' . $o['direction'], $this->orders)) : '';
        $sql .= $this->limit !== null ? ' limit ' . $this->limit : '';
        $allBindings = array_merge($bindings, $this->bindings['where']);
        if ($this->executor instanceof CacheAwareExecutorInterface) {
            return $this->executor->mutate(new Mutation(
                $this->table(),
                Mutation::UPDATE,
                $sql,
                $allBindings,
                keyLookup: $this->writeKeyLookup(),
                updatedColumns: array_map(static fn(int|string $c): string => (string) $c, array_keys($values)),
                selectKeys: $this->keySelector(),
                byKeys: fn(array $columns, array $keys): array => [$head . ' where ' . $this->compileKeyCondition($columns, $keys), array_merge($bindings, self::flatten($keys))],
                values: self::assignments($values),
            ));
        }

        return $this->executor->execute($sql, $allBindings);
    }

    /** @param array<string, mixed> $extra */
    public function increment(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Expression($this->grammar->wrap($column) . ' + ?', [$amount])] + $extra);
    }

    /** @param array<string, mixed> $extra */
    public function decrement(string $column, int|float $amount = 1, array $extra = []): int
    {
        return $this->update([$column => new Expression($this->grammar->wrap($column) . ' - ?', [$amount])] + $extra);
    }

    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new InvalidArgumentException('delete() without where(): use truncate or whereRaw("1 = 1") explicitly');
        }
        $head = 'delete from ' . $this->grammar->wrapTable($this->table());
        $sql = $head . $this->compileWheres();
        $sql .= $this->limit !== null ? ' limit ' . $this->limit : '';
        if ($this->executor instanceof CacheAwareExecutorInterface) {
            return $this->executor->mutate(new Mutation(
                $this->table(),
                Mutation::DELETE,
                $sql,
                $this->bindings['where'],
                keyLookup: $this->writeKeyLookup(),
                selectKeys: $this->keySelector(),
                byKeys: fn(array $columns, array $keys): array => [$head . ' where ' . $this->compileKeyCondition($columns, $keys), self::flatten($keys)],
            ));
        }

        return $this->executor->execute($sql, $this->bindings['where']);
    }

    // --- primary-key shapes (row cache) ------------------------------------------------------

    /**
     * Key columns and key tuples when this query is a plain lookup by key: `SELECT *` with only
     * `col = ?` conditions (one key, composite keys allowed) or a single `col IN (…)`, joined
     * with AND, no joins, grouping, ordering, offset or locking, and LIMIT only for a single key.
     *
     * @internal used by the row cache
     *
     * @return array{columns: list<string>, keys: list<list<int|string>>}|null
     */
    public function readKeyLookup(): ?array
    {
        if ($this->columns !== ['*'] || $this->distinct || $this->joins !== [] || $this->groups !== [] || $this->havings !== []
            || $this->orders !== [] || $this->offset !== null || $this->lockForUpdate || $this->bindings['select'] !== []) {
            return null;
        }
        $lookup = $this->writeKeyLookup();
        if ($lookup === null || ($this->limit !== null && ($this->limit < 1 || count($lookup['keys']) !== 1))) {
            return null;
        }

        return $lookup;
    }

    /**
     * Like {@see readKeyLookup()} for writes: only the WHERE clause matters, because ORDER BY and
     * LIMIT can only narrow the named rows.
     *
     * @internal used by the row cache
     *
     * @return array{columns: list<string>, keys: list<list<int|string>>}|null
     */
    public function writeKeyLookup(): ?array
    {
        if ($this->from === null || $this->wheres === []) {
            return null;
        }
        foreach ($this->wheres as $i => $where) {
            if ($i > 0 && $where->boolean !== 'and') {
                return null;
            }
        }
        $values = $this->bindings['where'];
        if (count($this->wheres) === 1 && $this->wheres[0]->type === 'in' && !$this->wheres[0]->not) {
            $column = $this->keyColumn($this->wheres[0]->column);
            if ($column === null) {
                return null;
            }
            $keys = [];
            foreach ($values as $value) {
                if (!is_int($value) && !is_string($value)) {
                    return null;
                }
                $keys[] = [$value];
            }

            return ['columns' => [$column], 'keys' => $keys];
        }
        $columns = [];
        $tuple = [];
        foreach ($this->wheres as $i => $where) {
            $column = $where->type === 'basic' && $where->operator === '=' ? $this->keyColumn($where->column) : null;
            $value = $values[$i] ?? null;
            if ($column === null || in_array($column, $columns, true) || (!is_int($value) && !is_string($value))) {
                return null;
            }
            $columns[] = $column;
            $tuple[] = $value;
        }

        return ['columns' => $columns, 'keys' => [$tuple]];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function assignments(array $values): array
    {
        $typed = [];
        foreach ($values as $column => $value) {
            $typed[(string) $column] = $value;
        }

        return $typed;
    }

    /** `id`, `users.id` or `` `users`.`id` `` on this table → `id`; anything else → null. */
    private function keyColumn(string $column): ?string
    {
        $column = str_replace('`', '', trim($column));
        $dot = strrpos($column, '.');
        if ($dot === false) {
            return $column;
        }

        return substr($column, 0, $dot) === $this->from ? substr($column, $dot + 1) : null;
    }

    /** @return Closure(list<string>): array{string, list<mixed>} */
    private function keySelector(): Closure
    {
        return function (array $columns): array {
            $wrapped = [];
            foreach ($columns as $column) {
                $wrapped[] = $this->grammar->wrap(is_string($column) ? $column : "");
            }
            $sql = 'select ' . implode(', ', $wrapped)
                . ' from ' . $this->grammar->wrapTable($this->table()) . $this->compileWheres();
            if ($this->orders !== []) {
                $sql .= ' order by ' . implode(', ', array_map(fn(array $o): string => $this->wrapColumn($o['column']) . ' ' . $o['direction'], $this->orders));
            }
            $sql .= $this->limit !== null ? ' limit ' . $this->limit : '';

            return [$sql . ' for update', $this->bindings['where']];
        };
    }

    /**
     * `col IN (?, …)` for one key column, `(a = ? and b = ?) or (…)` for composite keys.
     *
     * @param list<string>           $columns
     * @param list<list<int|string>> $keys
     */
    public function compileKeyCondition(array $columns, array $keys): string
    {
        if (count($columns) === 1) {
            return $this->grammar->wrap($columns[0]) . ' in (' . implode(', ', array_fill(0, count($keys), '?')) . ')';
        }
        $tuple = '(' . implode(' and ', array_map(fn(string $c): string => $this->grammar->wrap($c) . ' = ?', $columns)) . ')';

        return implode(' or ', array_fill(0, count($keys), $tuple));
    }

    /**
     * @param list<list<int|string>> $keys
     *
     * @return list<int|string>
     */
    private static function flatten(array $keys): array
    {
        $flat = [];
        foreach ($keys as $tuple) {
            foreach ($tuple as $value) {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    // --- internals --------------------------------------------------------------------------

    private function aggregate(string $function, string $column): mixed
    {
        $previousColumns = $this->columns;
        $previousSelect = $this->bindings['select'];
        $this->columns = [new Expression($function . '(' . ($column === '*' ? '*' : $this->grammar->wrap($column)) . ') as `aggregate`')];
        $this->bindings['select'] = [];
        try {
            $rows = $this->get();
        } finally {
            $this->columns = $previousColumns;
            $this->bindings['select'] = $previousSelect;
        }

        return $rows[0]['aggregate'] ?? null;
    }

    private function compileColumns(): string
    {
        return implode(', ', array_map(fn(string|Expression $c): string => $this->wrapColumn($c), $this->columns));
    }

    private function wrapColumn(string|Expression $column): string
    {
        return $column instanceof Expression ? $column->sql : $this->grammar->wrap($column);
    }

    private function compileWheres(): string
    {
        if ($this->wheres === []) {
            return '';
        }

        return ' where ' . $this->compileWhereList($this->wheres);
    }

    /** @param list<WhereClause> $wheres */
    private function compileWhereList(array $wheres): string
    {
        $parts = [];
        foreach ($wheres as $i => $where) {
            $parts[] = ($i === 0 ? '' : ' ' . $where->boolean . ' ') . $this->compileWhere($where);
        }

        return implode('', $parts);
    }

    private function compileWhere(WhereClause $where): string
    {
        return match ($where->type) {
            'basic' => $this->grammar->wrap($where->column) . ' ' . $where->operator . ' ?',
            'in' => $this->grammar->wrap($where->column) . ($where->not ? ' not in (' : ' in (') . implode(', ', array_fill(0, $where->count, '?')) . ')',
            'null' => $this->grammar->wrap($where->column) . ($where->not ? ' is not null' : ' is null'),
            'between' => $this->grammar->wrap($where->column) . ($where->not ? ' not between ? and ?' : ' between ? and ?'),
            'column' => $this->grammar->wrap($where->first) . ' ' . $where->operator . ' ' . $this->grammar->wrap($where->second),
            'raw' => '(' . $where->sql . ')',
            'nested' => $where->nested === null ? '' : '(' . $where->nested->compileWhereList($where->nested->wheres) . ')',
            default => throw new InvalidArgumentException('Unknown where type ' . $where->type),
        };
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $values
     * @return list<array<string, mixed>>
     */
    private function normaliseRows(array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('insert() requires values');
        }
        $first = $values[array_key_first($values)];
        if (!is_array($first)) {
            /** @var array<string, mixed> $values */
            return [$values];
        }
        $rows = [];
        foreach ($values as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('insert() rows must be arrays');
            }
            $typed = [];
            foreach ($row as $k => $v) {
                $typed[(string) $k] = $v;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{string, list<mixed>}
     */
    private function compileInsert(array $rows): array
    {
        $columns = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $bindings = [];
        $tuples = [];
        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                throw new InvalidArgumentException('All inserted rows must have the same columns in the same order');
            }
            $tuples[] = $placeholders;
            foreach ($row as $value) {
                $bindings[] = $value;
            }
        }
        $sql = 'insert into ' . $this->grammar->wrapTable($this->table()) . ' (' . implode(', ', array_map(fn(string $c): string => $this->grammar->wrap($c), $columns)) . ') values ' . implode(', ', $tuples);

        return [$sql, $bindings];
    }

    private function table(): string
    {
        return $this->from ?? throw new InvalidArgumentException('No table selected; call from() or DB::table()');
    }

    private function operator(string $operator): string
    {
        $lower = strtolower(trim($operator));
        if (!in_array($lower, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported operator '{$operator}'");
        }

        return $lower;
    }
}
