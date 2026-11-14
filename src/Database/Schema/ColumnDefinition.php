<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

use LogicException;
use Omnitrix\Core\Database\Query\Expression;

/**
 * One column of a {@see Blueprint}. Modifiers return the definition so they can be chained:
 * `$table->string('email')->unique()->comment('login name')`.
 */
final class ColumnDefinition
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public string|int|float|bool|Expression|null $default = null;

    public bool $unsigned = false;

    public bool $autoIncrement = false;

    public bool $primary = false;

    public bool $unique = false;

    public bool $index = false;

    public ?string $comment = null;

    public ?string $after = null;

    public bool $useCurrent = false;

    public bool $useCurrentOnUpdate = false;

    public ?string $charset = null;

    public ?string $collation = null;

    /**
     * @param array<string, int|list<string>> $parameters type parameters (length, precision, scale, values)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $parameters = [],
        private readonly ?Blueprint $blueprint = null,
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    public function default(string|int|float|bool|Expression|null $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function autoIncrement(): self
    {
        $this->autoIncrement = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function index(): self
    {
        $this->index = true;

        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    /** Places the column after another one (ALTER TABLE only). */
    public function after(string $column): self
    {
        $this->after = $column;

        return $this;
    }

    /** `DEFAULT CURRENT_TIMESTAMP` for timestamp and datetime columns. */
    public function useCurrent(): self
    {
        $this->useCurrent = true;

        return $this;
    }

    /** `ON UPDATE CURRENT_TIMESTAMP` for timestamp and datetime columns. */
    public function useCurrentOnUpdate(): self
    {
        $this->useCurrentOnUpdate = true;

        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;

        return $this;
    }

    public function collation(string $collation): self
    {
        $this->collation = $collation;

        return $this;
    }

    /**
     * Adds a foreign key on this column referencing `$table.$column`. Without a table name it
     * is derived from the column: `user_id` → `users`.
     */
    public function constrained(?string $table = null, string $column = 'id'): ForeignKeyDefinition
    {
        if ($this->blueprint === null) {
            throw new LogicException('constrained() requires a column created by a Blueprint');
        }
        $table ??= Blueprint::guessTable($this->name);

        return $this->blueprint->foreign($this->name)->references($column)->on($table);
    }
}
