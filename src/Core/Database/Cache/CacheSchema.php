<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

use Closure;

use function array_fill;
use function array_key_exists;
use function array_keys;
use function count;
use function implode;
use function in_array;
use function is_string;
use function microtime;
use function strtoupper;

/**
 * What the row cache needs to know about the schema, read from `information_schema` on first use
 * in each worker:
 *
 * - the key columns of every cached table (unless configured explicitly);
 * - the foreign keys through which writes to one table change rows of a cached table inside the
 *   database (`ON DELETE CASCADE`, `ON DELETE SET NULL`, `ON UPDATE CASCADE`, …), so those rows are
 *   invalidated too.
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

    private float $loadedAt = -1.0;

    private bool $complete = false;

    /**
     * @param Closure(string, list<mixed>): list<array<string, mixed>> $select runs a query on the connection
     */
    public function __construct(private readonly CacheConfig $config, private readonly Closure $select) {}

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
        if ($this->isCached($table)) {
            return true;
        }
        $this->load();

        return ($this->cascades[$table] ?? []) !== [];
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

    /** @return list<Cascade> */
    public function cascadesFrom(string $table): array
    {
        if (!$this->config->enabled) {
            return [];
        }
        $this->load();

        return $this->cascades[$table] ?? [];
    }

    private function load(): void
    {
        if ($this->complete || ($this->loadedAt >= 0.0 && microtime(true) - $this->loadedAt < 2.0)) {
            return;
        }
        $this->loadedAt = microtime(true);
        $tables = array_keys($this->config->tables);
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
            . ' k.constraint_name as name, r.delete_rule as on_delete, r.update_rule as on_update'
            . ' from information_schema.key_column_usage k join information_schema.referential_constraints r'
            . ' on r.constraint_schema = k.constraint_schema and r.constraint_name = k.constraint_name and r.table_name = k.table_name'
            . " where k.table_schema = database() and k.referenced_table_name is not null and k.table_name in ({$placeholders})",
            $tables,
        ) as $row) {
            $name = is_string($row['child'] ?? null) && is_string($row['name'] ?? null) ? $row['child'] . '.' . $row['name'] : null;
            if ($name !== null) {
                $constraints[$name][] = $row;
            }
        }
        $cascades = [];
        foreach ($constraints as $columns) {
            if (count($columns) !== 1) {
                continue; // composite foreign keys are not tracked; see the documentation
            }
            $row = $columns[0];
            $onDelete = is_string($row['on_delete'] ?? null) ? strtoupper($row['on_delete']) : '';
            $onUpdate = is_string($row['on_update'] ?? null) ? strtoupper($row['on_update']) : '';
            $deletes = in_array($onDelete, self::CHANGING_RULES, true);
            $updates = in_array($onUpdate, self::CHANGING_RULES, true);
            if ((!$deletes && !$updates) || !is_string($row['child'] ?? null) || !is_string($row['parent'] ?? null)
                || !is_string($row['child_column'] ?? null) || !is_string($row['parent_column'] ?? null)) {
                continue;
            }
            $cascades[$row['parent']][] = new Cascade($row['child'], $row['child_column'], $row['parent_column'], $deletes, $updates);
        }
        $this->cascades = $cascades;

        $this->complete = true;
        foreach ($tables as $table) {
            if ($this->config->tables[$table] === null && !isset($this->keys[$table])) {
                $this->complete = false; // not created yet; look again later
            }
        }
    }
}
