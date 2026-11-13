<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

/** Counts hits per key in fixed windows. */
interface RateLimiterInterface
{
    /** Records one hit and returns the decision. */
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult;
}
