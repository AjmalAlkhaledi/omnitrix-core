<?php

declare(strict_types=1);

namespace Omnitrix\Database\Schema;

use InvalidArgumentException;

use function in_array;
use function strtoupper;

/** A foreign key constraint: `$table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()`. */
final class ForeignKeyDefinition
{
    /** @var list<string> */
    public array $references = ['id'];

    public ?string $on = null;

    public string $onDelete = 'RESTRICT';

    public string $onUpdate = 'RESTRICT';

    public ?string $name = null;

    /** @param list<string> $columns */
    public function __construct(public readonly array $columns) {}

    public function references(string ...$columns): self
    {
        $this->references = array_values($columns);

        return $this;
    }

    public function on(string $table): self
    {
        $this->on = $table;

        return $this;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function onDelete(string $action): self
    {
        $this->onDelete = self::action($action);

        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->onUpdate = self::action($action);

        return $this;
    }

    public function cascadeOnDelete(): self
    {
        return $this->onDelete('cascade');
    }

    public function nullOnDelete(): self
    {
        return $this->onDelete('set null');
    }

    public function restrictOnDelete(): self
    {
        return $this->onDelete('restrict');
    }

    public function cascadeOnUpdate(): self
    {
        return $this->onUpdate('cascade');
    }

    private static function action(string $action): string
    {
        $action = strtoupper($action);
        if (!in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
            throw new InvalidArgumentException("Unsupported referential action '{$action}'");
        }

        return $action;
    }
}
