<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

/**
 * A foreign key through which the database itself changes rows of a cached child table when rows
 * of the parent table are deleted or have their referenced column updated.
 *
 * @internal
 */
final readonly class Cascade
{
    public function __construct(
        public string $childTable,
        public string $childColumn,
        public string $parentColumn,
        public bool $onDelete,
        public bool $onUpdate,
    ) {}
}
