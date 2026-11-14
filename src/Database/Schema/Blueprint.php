<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

use Omnitrix\Support\Str;

use function array_values;
use function implode;
use function str_ends_with;
use function substr;

/**
 * Describes a table to create or the changes to apply to an existing one. Used in migrations:
 *
 * ```php
 * $schema->create('posts', function (Blueprint $table): void {
 *     $table->id();
 *     $table->foreignId('user_id')->constrained()->cascadeOnDelete();
 *     $table->string('title');
 *     $table->text('body')->nullable();
 *     $table->timestamps();
 * });
 * ```
 */
final class Blueprint
{
    /** @var list<ColumnDefinition> */
    private array $columns = [];

    /** @var list<ForeignKeyDefinition> */
    private array $foreignKeys = [];

    /** @var list<array{type: 'primary'|'unique'|'index'|'fulltext', columns: list<string>, name: ?string}> */
    private array $indexes = [];

    /** @var list<string> */
    private array $dropColumns = [];

    /** @var list<string> */
    private array $dropIndexes = [];

    /** @var list<string> */
    private array $dropForeignKeys = [];

    /** @var list<array{from: string, to: string}> */
    private array $renameColumns = [];

    public string $engine = 'InnoDB';

    public string $charset = 'utf8mb4';

    public string $collation = 'utf8mb4_unicode_ci';

    public ?string $comment = null;

    public function __construct(public readonly string $table, public readonly bool $creating) {}

    // --- keys -------------------------------------------------------------------------------

    /** Auto-incrementing `BIGINT UNSIGNED` primary key. */
    public function id(string $column = 'id'): ColumnDefinition
    {
        return $this->bigIncrements($column);
    }

    public function bigIncrements(string $column): ColumnDefinition
    {
        return $this->add($column, 'bigint')->unsigned()->autoIncrement()->primary();
    }

    public function increments(string $column): ColumnDefinition
    {
        return $this->add($column, 'int')->unsigned()->autoIncrement()->primary();
    }

    /** `BIGINT UNSIGNED` column meant to reference another table's `id`; chain `->constrained()`. */
    public function foreignId(string $column): ColumnDefinition
    {
        return $this->add($column, 'bigint')->unsigned();
    }

    // --- strings ----------------------------------------------------------------------------

    public function string(string $column, int $length = 255): ColumnDefinition
    {
        return $this->add($column, 'varchar', ['length' => $length]);
    }

    public function char(string $column, int $length = 255): ColumnDefinition
    {
        return $this->add($column, 'char', ['length' => $length]);
    }

    public function text(string $column): ColumnDefinition
    {
        return $this->add($column, 'text');
    }

    public function mediumText(string $column): ColumnDefinition
    {
        return $this->add($column, 'mediumtext');
    }

    public function longText(string $column): ColumnDefinition
    {
        return $this->add($column, 'longtext');
    }

    public function uuid(string $column = 'uuid'): ColumnDefinition
    {
        return $this->add($column, 'char', ['length' => 36]);
    }

    /** @param list<string> $values */
    public function enum(string $column, array $values): ColumnDefinition
    {
        return $this->add($column, 'enum', ['values' => array_values($values)]);
    }

    public function json(string $column): ColumnDefinition
    {
        return $this->add($column, 'json');
    }

    public function binary(string $column): ColumnDefinition
    {
        return $this->add($column, 'blob');
    }

    // --- numbers ----------------------------------------------------------------------------

    public function tinyInteger(string $column): ColumnDefinition
    {
        return $this->add($column, 'tinyint');
    }

    public function smallInteger(string $column): ColumnDefinition
    {
        return $this->add($column, 'smallint');
    }

    public function integer(string $column): ColumnDefinition
    {
        return $this->add($column, 'int');
    }

    public function bigInteger(string $column): ColumnDefinition
    {
        return $this->add($column, 'bigint');
    }

    public function unsignedInteger(string $column): ColumnDefinition
    {
        return $this->integer($column)->unsigned();
    }

    public function unsignedBigInteger(string $column): ColumnDefinition
    {
        return $this->bigInteger($column)->unsigned();
    }

    public function decimal(string $column, int $precision = 8, int $scale = 2): ColumnDefinition
    {
        return $this->add($column, 'decimal', ['precision' => $precision, 'scale' => $scale]);
    }

    public function float(string $column): ColumnDefinition
    {
        return $this->add($column, 'float');
    }

    public function double(string $column): ColumnDefinition
    {
        return $this->add($column, 'double');
    }

    public function boolean(string $column): ColumnDefinition
    {
        return $this->add($column, 'boolean');
    }

    // --- dates ------------------------------------------------------------------------------

    public function date(string $column): ColumnDefinition
    {
        return $this->add($column, 'date');
    }

    public function time(string $column): ColumnDefinition
    {
        return $this->add($column, 'time');
    }

    public function dateTime(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->add($column, 'datetime', ['precision' => $precision]);
    }

    public function timestamp(string $column, int $precision = 0): ColumnDefinition
    {
        return $this->add($column, 'timestamp', ['precision' => $precision]);
    }

    /** Nullable `created_at` and `updated_at`, maintained by models with timestamps enabled. */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    // --- indexes and constraints ------------------------------------------------------------

    /** @param string|list<string> $columns */
    public function primary(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'primary', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function fullText(string|array $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'fulltext', 'columns' => (array) $columns, 'name' => $name];
    }

    /** @param string|list<string> $columns */
    public function foreign(string|array $columns): ForeignKeyDefinition
    {
        $foreign = new ForeignKeyDefinition((array) $columns);
        $this->foreignKeys[] = $foreign;

        return $foreign;
    }

    // --- changes to existing tables ---------------------------------------------------------

    public function dropColumn(string ...$columns): void
    {
        foreach ($columns as $column) {
            $this->dropColumns[] = $column;
        }
    }

    public function renameColumn(string $from, string $to): void
    {
        $this->renameColumns[] = ['from' => $from, 'to' => $to];
    }

    /** Drops an index by name (see {@see indexName()} for generated names). */
    public function dropIndex(string $name): void
    {
        $this->dropIndexes[] = $name;
    }

    /** Drops a foreign key by name (see {@see indexName()} for generated names). */
    public function dropForeign(string $name): void
    {
        $this->dropForeignKeys[] = $name;
    }

    // --- accessors for the grammar ----------------------------------------------------------

    /** @return list<ColumnDefinition> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<ForeignKeyDefinition> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    /**
     * Explicit indexes plus those declared on columns (`->unique()`, `->index()`).
     *
     * @return list<array{type: 'primary'|'unique'|'index'|'fulltext', columns: list<string>, name: string}>
     */
    public function indexes(): array
    {
        $indexes = [];
        foreach ($this->columns as $column) {
            if ($column->unique) {
                $indexes[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => $this->indexName('unique', [$column->name])];
            }
            if ($column->index) {
                $indexes[] = ['type' => 'index', 'columns' => [$column->name], 'name' => $this->indexName('index', [$column->name])];
            }
        }
        foreach ($this->indexes as $index) {
            $indexes[] = ['type' => $index['type'], 'columns' => $index['columns'], 'name' => $index['name'] ?? $this->indexName($index['type'], $index['columns'])];
        }

        return $indexes;
    }

    /** @return list<string> */
    public function droppedColumns(): array
    {
        return $this->dropColumns;
    }

    /** @return list<string> */
    public function droppedIndexes(): array
    {
        return $this->dropIndexes;
    }

    /** @return list<string> */
    public function droppedForeignKeys(): array
    {
        return $this->dropForeignKeys;
    }

    /** @return list<array{from: string, to: string}> */
    public function renamedColumns(): array
    {
        return $this->renameColumns;
    }

    /**
     * Generated constraint name: `{table}_{columns}_{type}`, e.g. `posts_user_id_foreign`.
     *
     * @param list<string> $columns
     */
    public function indexName(string $type, array $columns): string
    {
        return Str::snake($this->table . '_' . implode('_', $columns) . '_' . ($type === 'fulltext' ? 'fulltext' : $type));
    }

    /** `user_id` → `users`, `category_id` → `categories`. */
    public static function guessTable(string $column): string
    {
        $base = str_ends_with($column, '_id') ? substr($column, 0, -3) : $column;

        return Str::plural($base);
    }

    /** @param array<string, int|list<string>> $parameters */
    private function add(string $name, string $type, array $parameters = []): ColumnDefinition
    {
        $column = new ColumnDefinition($name, $type, $parameters, $this);
        $this->columns[] = $column;

        return $column;
    }
}
