<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Query;

/** One compiled-later WHERE condition. */
final readonly class WhereClause
{
    public function __construct(
        public string $type,
        public string $boolean = 'and',
        public string $column = '',
        public string $operator = '',
        public bool $not = false,
        public int $count = 0,
        public string $sql = '',
        public ?Builder $nested = null,
        public string $first = '',
        public string $second = '',
    ) {}
}
