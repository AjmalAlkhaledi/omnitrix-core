<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Cache;

/**
 * Outcome of looking up one row in the {@see RowCache}.
 *
 * @internal
 */
final readonly class LookupResult
{
    /**
     * @param array<string, mixed>|null $row
     */
    private function __construct(
        public bool $hit,
        public ?array $row,
        public bool $fillable,
        public ?string $versionToken,
        public ?string $epochToken,
        public ?string $generationToken = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function hit(array $row): self
    {
        return new self(true, $row, false, null, null);
    }

    /** Cached knowledge that the row does not exist. */
    public static function absent(): self
    {
        return new self(true, null, false, null, null);
    }

    /** A writer is changing the row: read the database and do not store the result. */
    public static function bypass(): self
    {
        return new self(false, null, false, null, null);
    }

    /**
     * Not cached (or stale): read the database, then fill under the observed tokens.
     *
     * @param bool $epochFillable false while an insert into the table is in progress: absent rows must not be cached then
     */
    public static function miss(?string $versionToken, ?string $epochToken, bool $epochFillable, ?string $generationToken = null): self
    {
        return new self(false, null, true, $versionToken, $epochFillable ? $epochToken : null, $generationToken);
    }
}
