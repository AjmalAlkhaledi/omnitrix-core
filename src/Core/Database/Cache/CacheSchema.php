<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Closure;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function array_pop;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_string;
use function microtime;
use function preg_match;
use function preg_match_all;
use function sort;
use function strtolower;
use function strtoupper;

use const PREG_SET_ORDER;

/**
 * What the row cache needs to know about the schema, read from `information_schema` on first use
 * in each worker:
 *
 * - the key columns of every cached table (unless configured explicitly);
 * - the foreign keys through which writes to one table change rows of a cached table inside the
 *   database (`ON DELETE CASCADE`, `ON DELETE SET NULL`, `ON UPDATE CASCADE`, …), so those rows are
 *   invalidated too;
 * - the triggers that write to cached tables, whose tables are invalidated as a whole by writes
 *   to the table the trigger belongs to (the rows a trigger changes cannot be known).
 *
 * Tables that do not exist yet (before their migration ran) are looked up again on later use.
 * Schema changes made while workers run are picked up after a reload or restart.
 *
 * @internal
 */
final class CacheSchema
{
    private const array CHANGING_RULES = ['CASCADE', 'SET NULL', 'SET DEFAULT'];

    /** @var array<string, list<string>> table => key columns */
    private array $keys = [];

    /** @var array<string, list<Cascade>> parent table => cascades into cached tables */
    private array $cascades = [];

    /** @var array<string, array<string, array{kind: 'int'|'string', charset: string, collation: string}>> */
    private array $columns = [];

    /** @var array<string, array<string, list<string>>> table => event (INSERT|UPDATE|DELETE) => tables its triggers write */
    private array $triggers = [];

    private float $loadedAt = -1.0;

    private bool $complete = false;

    /**
     * @param Closure(string, list<mixed>): list<array<string, mixed>> $select runs a query on the connection
     */
    public function __construct(private readonly CacheConfig $config, private readonly Closure $select) {}

    /** Whether the table has a row cache or a query cache. */
    public function isConfigured(string $table): bool
    {
        return $this->config->enabled && (array_key_exists($table, $this->config->tables) || isset($this->config->queries[$table]));
    }

    public function isCached(string $table): bool
    {
        return $this->config->enabled && array_key_exists($table, $this->config->tables);
    }

    /**
     * Whether writes to `$table` can change cached rows: the table is cached, or deleting/updating
     * its rows cascades into a cached table.
     */
    public function involves(string $table): bool
    {
        if (!$this->config->enabled) {
            return false;
        }
        if ($this->isCached($table) || isset($this->config->queries[$table])) {
            return true;
        }
        $this->load();

        return ($this->cascades[$table] ?? []) !== [] || $this->triggerTargets($table, ['INSERT', 'UPDATE', 'DELETE']) !== [];
    }

    /**
     * Cached tables (row or query cache) that triggers on `$table` can change for the given
     * events, directly or through the foreign-key cascades and triggers of the tables they write.
     * A trigger calling a stored procedure, or writing with a join, may change any table: every
     * configured table is returned for it.
     *
     * @param list<'INSERT'|'UPDATE'|'DELETE'> $events
     *
     * @return list<string>
     */
    public function triggerTargets(string $table, array $events): array
    {
        if (!$this->config->enabled) {
            return [];
        }
        $this->load();
        /** @var list<string> $pending */
        $pending = [];
        foreach ($events as $event) {
            foreach ($this->triggers[$table][$event] ?? [] as $target) {
                $pending[] = $target;
            }
        }
        /** @var array<string, true> $seen */
        $seen = [];
        while ($pending !== []) {
            $current = (string) array_pop($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($this->cascades[$current] ?? [] as $cascade) {
                $pending[] = $cascade->childTable;
            }
            foreach ($this->triggers[$current] ?? [] as $targets) {
                foreach ($targets as $target) {
                    $pending[] = $target;
                }
            }
        }
        $configured = [];
        foreach (array_keys($seen) as $candidate) {
            if ($candidate !== $table && $this->isConfigured($candidate)) {
                $configured[] = $candidate;
            }
        }
        sort($configured);

        return $configured;
    }

    /**
     * Tables a trigger body writes to; every configured table when the targets cannot be known.
     *
     * @return list<string>
     */
    private function triggerWrites(string $body): array
    {
        if (preg_match('/\b(call|join|prepare|execute)\b/i', $body) === 1) {
            return array_keys($this->config->tables + $this->config->queries);
        }
        preg_match_all(
            '/\b(?:insert\s+(?:(?:low_priority|delayed|high_priority|ignore)\s+)*(?:into\s+)?|replace\s+(?:(?:low_priority|delayed)\s+)*(?:into\s+)?|update\s+(?:(?:low_priority|ignore)\s+)*|delete\s+(?:(?:low_priority|quick|ignore)\s+)*from\s+)`?([a-z0-9_$]+)`?(?:\s*\.\s*`?([a-z0-9_$]+)`?)?/i',
            $body,
            $matches,
            PREG_SET_ORDER,
        );
        $tables = [];
        foreach ($matches as $match) {
            $tables[] = isset($match[2]) && $match[2] !== '' ? $match[2] : $match[1];
        }

        return array_values(array_unique($tables));
    }

    /**
     * Key columns of a cached table, or null when the table is not cached or its key is not known
     * (the table does not exist yet).
     *
     * @return list<string>|null
     */
    public function keyColumns(string $table): ?array
    {
        if (!$this->isCached($table)) {
            return null;
        }
        $configured = $this->config->tables[$table];
        if ($configured !== null) {
            return $configured;
        }
        $this->load();

        return $this->keys[$table] ?? null;
    }

    /**
     * Columns of `$table` that cached queries may depend on, with how their values compare, or
     * null when the table has no query cache. Integer columns compare by value; text columns by
     * their collation.
     *
     * @return array<string, array{kind: 'int'|'string', charset: string, collation: string}>|null
     *
     * @throws InvalidConfigurationException when a configured column does not exist or has an unsupported type
     */
    public function trackedColumns(string $table): ?array
    {
        if (!$this->config->enabled || !isset($this->config->queries[$table])) {
            return null;
        }
        $this->load();
        if (!isset($this->columns[$table])) {
            return null; // the table does not exist yet
        }
        $tracked = [];
        foreach ($this->config->queries[$table] as $column) {
            $info = $this->columns[$table][$column] ?? null;
            if ($info === null) {
                throw InvalidConfigurationException::at("database cache.queries.{$table}", "column '{$column}' does not exist or has an unsupported type (integer, char, varchar, text and enum columns are supported)");
            }
            $tracked[$column] = $info;
        }

        return $tracked;
    }

    /**
     * Primary key columns of any table that takes part in caching (row cache or query cache).
     *
     * @return list<string>|null
     */
    public function primaryKey(string $table): ?array
    {
        if (!$this->config->enabled) {
            return null;
        }
        $configured = $this->config->tables[$table] ?? null;
        if ($configured !== null) {
            return $configured;
        }
        if (!array_key_exists($table, $this->config->tables) && !isset($this->config->queries[$table])) {
            return null;
        }
        $this->load();

        return $this->keys[$table] ?? null;
    }

    /** @return list<Cascade> */
    public function cascadesFrom(string $table): array
    {
        if (!$this->config->enabled) {
            return [];
        }
        $this->load();

        return $this->cascades[$table] ?? [];
    }

    /** Forgets the loaded metadata; the next use reads the schema again (after DDL). */
    public function reset(): void
    {
        $this->complete = false;
        $this->loadedAt = -1.0;
    }

    private function load(): void
    {
        if ($this->complete || ($this->loadedAt >= 0.0 && microtime(true) - $this->loadedAt < 2.0)) {
            return;
        }
        $this->loadedAt = microtime(true);
        $tables = array_keys($this->config->tables + $this->config->queries);
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));

        $keys = [];
        foreach (($this->select)(
            "select table_name as t, column_name as c from information_schema.key_column_usage where table_schema = database() and constraint_name = 'PRIMARY' and table_name in ({$placeholders}) order by table_name, ordinal_position",
            $tables,
        ) as $row) {
            if (is_string($row['t'] ?? null) && is_string($row['c'] ?? null)) {
                $keys[$row['t']][] = $row['c'];
            }
        }
        $this->keys = $keys;

        $constraints = [];
        foreach (($this->select)(
            'select k.table_name as child, k.column_name as child_column, k.referenced_table_name as parent, k.referenced_column_name as parent_column,'
            . ' k.constraint_name as name, r.delete_rule as on_delete, r.update_rule as on_update, k.ordinal_position as position'
            . ' from information_schema.key_column_usage k join information_schema.referential_constraints r'
            . ' on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name'
            . " where k.table_schema = database() and k.referenced_table_name is not null and k.table_name in ({$placeholders})"
            . ' order by k.table_name, k.constraint_name, k.ordinal_position',
            $tables,
        ) as $row) {
            $name = is_string($row['child'] ?? null) && is_string($row['name'] ?? null) ? $row['child'] . '.' . $row['name'] : null;
            if ($name !== null) {
                $constraints[$name][] = $row;
            }
        }
        $cascades = [];
        foreach ($constraints as $columns) {
            $row = $columns[0];
            $onDelete = is_string($row['on_delete'] ?? null) ? strtoupper($row['on_delete']) : '';
            $onUpdate = is_string($row['on_update'] ?? null) ? strtoupper($row['on_update']) : '';
            $deletes = in_array($onDelete, self::CHANGING_RULES, true);
            $updates = in_array($onUpdate, self::CHANGING_RULES, true);
            if ((!$deletes && !$updates) || !is_string($row['child'] ?? null) || !is_string($row['parent'] ?? null)) {
                continue;
            }
            $childColumns = [];
            $parentColumns = [];
            foreach ($columns as $column) {
                if (!is_string($column['child_column'] ?? null) || !is_string($column['parent_column'] ?? null)) {
                    continue 2;
                }
                $childColumns[] = $column['child_column'];
                $parentColumns[] = $column['parent_column'];
            }
            $cascades[$row['parent']][] = new Cascade($row['child'], $childColumns, $parentColumns, $deletes, $updates);
        }
        $this->cascades = $cascades;

        $triggers = [];
        foreach (($this->select)(
            'select event_object_table as t, event_manipulation as e, action_statement as body from information_schema.triggers where trigger_schema = database()',
            [],
        ) as $row) {
            $event = is_string($row['e'] ?? null) ? strtoupper($row['e']) : '';
            if (!is_string($row['t'] ?? null) || !is_string($row['body'] ?? null) || !in_array($event, ['INSERT', 'UPDATE', 'DELETE'], true)) {
                continue;
            }
            foreach ($this->triggerWrites($row['body']) as $target) {
                $triggers[$row['t']][$event][] = $target;
            }
        }
        $this->triggers = $triggers;

        $columns = [];
        $queryTables = array_keys($this->config->queries);
        if ($queryTables !== []) {
            $marks = implode(', ', array_fill(0, count($queryTables), '?'));
            foreach (($this->select)(
                'select table_name as t, column_name as c, data_type as type, character_set_name as charset, collation_name as collation'
                . " from information_schema.columns where table_schema = database() and table_name in ({$marks})",
                $queryTables,
            ) as $row) {
                $type = is_string($row['type'] ?? null) ? strtolower($row['type']) : '';
                if (!is_string($row['t'] ?? null) || !is_string($row['c'] ?? null)) {
                    continue;
                }
                if (in_array($type, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
                    $columns[$row['t']][$row['c']] = ['kind' => 'int', 'charset' => '', 'collation' => ''];
                } elseif (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum'], true)
                    && is_string($row['charset'] ?? null) && is_string($row['collation'] ?? null)) {
                    $columns[$row['t']][$row['c']] = ['kind' => 'string', 'charset' => $row['charset'], 'collation' => $row['collation']];
                }
            }
        }
        $this->columns = $columns;

        $this->complete = true;
        foreach ($tables as $table) {
            if ((array_key_exists($table, $this->config->tables) && $this->config->tables[$table] === null && !isset($this->keys[$table]))
                || (isset($this->config->queries[$table]) && !isset($columns[$table]))) {
                $this->complete = false; // not created yet; look again later
            }
        }
    }
}
