<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

/**
 * A foreign key through which the database itself changes rows of a cached child table when rows
 * of the parent table are deleted or have their referenced columns updated. Composite foreign keys
 * list their columns in constraint order.
 *
 * @internal
 */
final readonly class Cascade
{
    /**
     * @param list<string> $childColumns  referencing columns of the child table
     * @param list<string> $parentColumns referenced columns of the parent table, same order
     */
    public function __construct(
        public string $childTable,
        public array $childColumns,
        public array $parentColumns,
        public bool $onDelete,
        public bool $onUpdate,
    ) {}
}
