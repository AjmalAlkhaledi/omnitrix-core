<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Query;

/**
 * A raw SQL fragment the builder inserts verbatim. Only ever build one from trusted,
 * developer-written SQL — never from request input.
 */
final readonly class Expression
{
    /** @param list<mixed> $bindings */
    public function __construct(public string $sql, public array $bindings = []) {}

    public function __toString(): string
    {
        return $this->sql;
    }
}
