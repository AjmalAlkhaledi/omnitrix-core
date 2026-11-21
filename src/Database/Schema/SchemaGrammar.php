<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

use InvalidArgumentException;
use Omnitrix\Core\Database\Query\Expression;

use function array_map;
use function implode;
use function in_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_replace;
use function strtoupper;

/** Compiles {@see Blueprint}s into MySQL/MariaDB DDL statements. */
final class SchemaGrammar implements SchemaGrammarInterface
{
    /** @return list<string> */
    public function compile(Blueprint $blueprint): array
    {
        return $blueprint->creating ? [$this->compileCreate($blueprint)] : $this->compileAlter($blueprint);
    }

    public function compileCreate(Blueprint $blueprint): string
    {
        $lines = array_map(fn(ColumnDefinition $c): string => $this->column($c, inlinePrimary: true), $blueprint->columns());
        foreach ($blueprint->indexes() as $index) {
            $lines[] = $this->indexClause($index);
        }
        foreach ($blueprint->foreignKeys() as $foreign) {
            $lines[] = $this->foreignClause($blueprint, $foreign);
        }
        $sql = 'CREATE TABLE ' . $this->wrap($blueprint->table) . " (\n    " . implode(",\n    ", $lines) . "\n)";
        $sql .= ' ENGINE=' . $blueprint->engine . ' DEFAULT CHARSET=' . $blueprint->charset . ' COLLATE=' . $blueprint->collation;
        if ($blueprint->comment !== null) {
            $sql .= ' COMMENT=' . $this->quote($blueprint->comment);
        }

        return $sql;
    }

    /** @return list<string> */
    public function compileAlter(Blueprint $blueprint): array
    {
        $changes = [];
        foreach ($blueprint->droppedForeignKeys() as $name) {
            $changes[] = 'DROP FOREIGN KEY ' . $this->wrap($name);
        }
        foreach ($blueprint->droppedIndexes() as $name) {
            $changes[] = 'DROP INDEX ' . $this->wrap($name);
        }
        foreach ($blueprint->renamedColumns() as $rename) {
            $changes[] = 'RENAME COLUMN ' . $this->wrap($rename['from']) . ' TO ' . $this->wrap($rename['to']);
        }
        foreach ($blueprint->columns() as $column) {
            $changes[] = 'ADD COLUMN ' . $this->column($column, inlinePrimary: true) . ($column->after !== null ? ' AFTER ' . $this->wrap($column->after) : '');
        }
        foreach ($blueprint->indexes() as $index) {
            $changes[] = 'ADD ' . $this->indexClause($index);
        }
        foreach ($blueprint->foreignKeys() as $foreign) {
            $changes[] = 'ADD ' . $this->foreignClause($blueprint, $foreign);
        }
        foreach ($blueprint->droppedColumns() as $column) {
            $changes[] = 'DROP COLUMN ' . $this->wrap($column);
        }

        return $changes === [] ? [] : ['ALTER TABLE ' . $this->wrap($blueprint->table) . ' ' . implode(', ', $changes)];
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->wrap($table);
    }

    public function compileRename(string $from, string $to): string
    {
        return 'RENAME TABLE ' . $this->wrap($from) . ' TO ' . $this->wrap($to);
    }

    public function wrap(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    public function currentSchema(): string
    {
        return 'database()';
    }

    public function compileDropAll(array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $statements = ['SET FOREIGN_KEY_CHECKS = 0'];
        foreach ($tables as $table) {
            $statements[] = $this->compileDrop($table, ifExists: true);
        }
        $statements[] = 'SET FOREIGN_KEY_CHECKS = 1';

        return $statements;
    }

    public function column(ColumnDefinition $column, bool $inlinePrimary): string
    {
        $sql = $this->wrap($column->name) . ' ' . $this->type($column);
        if ($column->unsigned && in_array($column->type, ['tinyint', 'smallint', 'int', 'bigint', 'decimal', 'float', 'double'], true)) {
            $sql .= ' UNSIGNED';
        }
        if ($column->charset !== null) {
            $sql .= ' CHARACTER SET ' . $column->charset;
        }
        if ($column->collation !== null) {
            $sql .= ' COLLATE ' . $column->collation;
        }
        $sql .= $column->nullable ? ' NULL' : ' NOT NULL';
        if ($column->useCurrent) {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        } elseif ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column->default);
        }
        if ($column->useCurrentOnUpdate) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }
        if ($column->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }
        if ($inlinePrimary && $column->primary) {
            $sql .= ' PRIMARY KEY';
        }
        if ($column->comment !== null) {
            $sql .= ' COMMENT ' . $this->quote($column->comment);
        }

        return $sql;
    }

    private function type(ColumnDefinition $column): string
    {
        $p = $column->parameters;
        $int = static fn(string $key, int $default): int => is_int($p[$key] ?? null) ? $p[$key] : $default;

        return match ($column->type) {
            'varchar' => 'VARCHAR(' . $int('length', 255) . ')',
            'char' => 'CHAR(' . $int('length', 255) . ')',
            'text', 'mediumtext', 'longtext', 'json', 'blob', 'date', 'time', 'float', 'double', 'tinyint', 'smallint', 'int', 'bigint' => strtoupper($column->type),
            'boolean' => 'TINYINT(1)',
            'decimal' => 'DECIMAL(' . $int('precision', 8) . ', ' . $int('scale', 2) . ')',
            'datetime', 'timestamp' => strtoupper($column->type) . ($int('precision', 0) > 0 ? '(' . $int('precision', 0) . ')' : ''),
            'enum' => 'ENUM(' . implode(', ', array_map($this->quote(...), $this->values($p['values'] ?? []))) . ')',
            default => throw new InvalidArgumentException("Unsupported column type '{$column->type}'"),
        };
    }

    /**
     * @param int|list<string> $values
     *
     * @return list<string>
     */
    private function values(int|array $values): array
    {
        return is_int($values) ? [] : $values;
    }

    /** @param array{type: string, columns: list<string>, name: string} $index */
    private function indexClause(array $index): string
    {
        $columns = '(' . implode(', ', array_map($this->wrap(...), $index['columns'])) . ')';

        return match ($index['type']) {
            'primary' => 'PRIMARY KEY ' . $columns,
            'unique' => 'UNIQUE KEY ' . $this->wrap($index['name']) . ' ' . $columns,
            'fulltext' => 'FULLTEXT KEY ' . $this->wrap($index['name']) . ' ' . $columns,
            default => 'KEY ' . $this->wrap($index['name']) . ' ' . $columns,
        };
    }

    private function foreignClause(Blueprint $blueprint, ForeignKeyDefinition $foreign): string
    {
        if ($foreign->on === null) {
            throw new InvalidArgumentException('Foreign key on ' . implode(', ', $foreign->columns) . " of '{$blueprint->table}' needs a referenced table (->on())");
        }
        $name = $foreign->name ?? $blueprint->indexName('foreign', $foreign->columns);

        return 'CONSTRAINT ' . $this->wrap($name) . ' FOREIGN KEY (' . implode(', ', array_map($this->wrap(...), $foreign->columns)) . ')'
            . ' REFERENCES ' . $this->wrap($foreign->on) . ' (' . implode(', ', array_map($this->wrap(...), $foreign->references)) . ')'
            . ' ON DELETE ' . $foreign->onDelete . ' ON UPDATE ' . $foreign->onUpdate;
    }

    private function defaultValue(string|int|float|bool|Expression|null $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => $this->quote($value),
            default => $value->sql,
        };
    }

    private function quote(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $value) . "'";
    }
}
