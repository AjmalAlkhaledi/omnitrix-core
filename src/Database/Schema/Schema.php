<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

use Omnitrix\Core\Database\Database;

use function array_map;
use function array_values;
use function is_string;
use function sort;

use const SORT_STRING;

/**
 * Creates, changes and drops tables on one connection (MySQL/MariaDB). Migrations receive an
 * instance; elsewhere use `new Schema(DB::connection())`.
 */
final class Schema
{
    private readonly SchemaGrammar $grammar;

    public function __construct(private readonly Database $db)
    {
        $this->grammar = new SchemaGrammar();
    }

    /** @param callable(Blueprint): void $define */
    public function create(string $table, callable $define): void
    {
        $blueprint = new Blueprint($table, creating: true);
        $define($blueprint);
        $this->run($blueprint);
    }

    /** @param callable(Blueprint): void $define */
    public function table(string $table, callable $define): void
    {
        $blueprint = new Blueprint($table, creating: false);
        $define($blueprint);
        $this->run($blueprint);
    }

    public function drop(string $table): void
    {
        $this->db->execute($this->grammar->compileDrop($table, ifExists: false));
        $this->db->flushCache($table);
    }

    public function dropIfExists(string $table): void
    {
        $this->db->execute($this->grammar->compileDrop($table, ifExists: true));
        $this->db->flushCache($table);
    }

    public function rename(string $from, string $to): void
    {
        $this->db->execute($this->grammar->compileRename($from, $to));
        $this->db->flushCache($from, $to);
    }

    public function hasTable(string $table): bool
    {
        return $this->db->selectOne('select 1 as found from information_schema.tables where table_schema = database() and table_name = ?', [$table]) !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        return $this->db->selectOne('select 1 as found from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?', [$table, $column]) !== null;
    }

    /** @return list<string> base tables of the current database, sorted by byte value */
    public function tables(): array
    {
        $rows = $this->db->select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'");

        $tables = array_values(array_map(static fn(array $row): string => is_string($row['name'] ?? null) ? $row['name'] : '', $rows));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Drops every table of the current database, ignoring foreign keys while doing so. Used by
     * `migrate:fresh`; never call it against a database that holds data you need.
     */
    public function dropAllTables(): void
    {
        $tables = $this->tables();
        if ($tables === []) {
            return;
        }
        $connection = $this->db->acquire();
        try {
            $connection->execute('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $table) {
                    $connection->execute($this->grammar->compileDrop($table, ifExists: true));
                }
            } finally {
                $connection->execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        } finally {
            $connection->release();
            $this->db->flushCache(...$tables);
        }
    }

    /** @return list<string> the statements a blueprint compiles to, without running them */
    public function toSql(Blueprint $blueprint): array
    {
        return $this->grammar->compile($blueprint);
    }

    private function run(Blueprint $blueprint): void
    {
        foreach ($this->grammar->compile($blueprint) as $sql) {
            $this->db->execute($sql);
        }
        // Rows cached for an earlier table of the same name, or before columns changed, are retired.
        $this->db->flushCache($blueprint->table);
    }
}
