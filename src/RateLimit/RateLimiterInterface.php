<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

/** Counts hits per key in fixed windows. */
interface RateLimiterInterface
{
    /** Records one hit and returns the decision. */
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult;

    /** Hits recorded for `$key` in the current window (without recording one). */
    public function attempts(string $key, int $windowSeconds): int;

    /** Seconds until the current window of `$windowSeconds` ends. */
    public function availableIn(string $key, int $windowSeconds): int;

    /** Forgets the hits of `$key` in the current window. */
    public function reset(string $key, int $windowSeconds): void;
}
