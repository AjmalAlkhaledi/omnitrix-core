<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Exception;

use Throwable;

use function count;
use function mb_strimwidth;

/** A statement failed. Carries the SQL (truncated) and SQLSTATE; bindings are never included. */
class QueryException extends DatabaseException
{
    public function __construct(string $message, private readonly string $sql, private readonly ?string $sqlState, private readonly int $driverCode, private readonly int $bindingCount, ?Throwable $previous = null)
    {
        parent::__construct($message, $driverCode, $previous);
    }

    public function sql(): string
    {
        return $this->sql;
    }

    public function sqlState(): ?string
    {
        return $this->sqlState;
    }

    public function driverCode(): int
    {
        return $this->driverCode;
    }

    public function bindingCount(): int
    {
        return $this->bindingCount;
    }

    /** @param array<mixed> $bindings */
    public static function describe(string $sql, array $bindings): string
    {
        return mb_strimwidth($sql, 0, 300, '…') . ' [' . count($bindings) . ' bindings]';
    }
}
