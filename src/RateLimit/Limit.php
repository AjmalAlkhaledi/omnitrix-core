<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use Closure;
use InvalidArgumentException;

/**
 * One rule of a named limiter: `Limit::perMinute(60)->by($request->user()?->id ?? $ip)`.
 * Without `by()` the request is keyed by identity, then client address.
 */
final class Limit
{
    public string $key = '';

    /** @var (Closure(\Omnitrix\Http\Request, RateLimitResult): \Omnitrix\Http\Response)|null */
    public ?Closure $response = null;

    public function __construct(public readonly int $maxAttempts, public readonly int $decaySeconds)
    {
        if ($maxAttempts < 1 || $decaySeconds < 1) {
            throw new InvalidArgumentException('A limit needs at least one attempt and a window of at least one second');
        }
    }

    public static function perSecond(int $maxAttempts, int $seconds = 1): self
    {
        return new self($maxAttempts, $seconds);
    }

    public static function perMinute(int $maxAttempts, int $minutes = 1): self
    {
        return new self($maxAttempts, 60 * $minutes);
    }

    public static function perHour(int $maxAttempts, int $hours = 1): self
    {
        return new self($maxAttempts, 3600 * $hours);
    }

    public static function perDay(int $maxAttempts, int $days = 1): self
    {
        return new self($maxAttempts, 86400 * $days);
    }

    /** Counts hits per this key (user id, client address, API key …). */
    public function by(string|int $key): self
    {
        $this->key = (string) $key;

        return $this;
    }

    /**
     * The response for a request over the limit (default: JSON 429 with `Retry-After`).
     *
     * @param callable(\Omnitrix\Http\Request, RateLimitResult): \Omnitrix\Http\Response $response
     */
    public function response(callable $response): self
    {
        $this->response = $response(...);

        return $this;
    }
}
