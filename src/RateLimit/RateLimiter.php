<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use Closure;
use InvalidArgumentException;
use Omnitrix\Http\Request;

use function is_array;
use function max;

/**
 * Named limiters and direct rate limiting on one backend (shared memory for one server, Redis
 * across servers).
 *
 * ```php
 * $limiter->for('api', fn (Request $r) => Limit::perMinute(60)->by(BearerTokenAuth::identity($r)?->id ?? $r->remoteAddress()));
 * $limiter->for('login', fn (Request $r) => [Limit::perMinute(5)->by($r->input('email')), Limit::perMinute(20)->by($r->remoteAddress())]);
 *
 * if (!$limiter->attempt('send-otp:' . $phone, 3, 600, fn () => $sms->send(...))) { // over the limit
 * ```
 */
final class RateLimiter
{
    /** @var array<string, Closure(Request): (Limit|list<Limit>|null)> */
    private array $limiters = [];

    public function __construct(private readonly RateLimiterInterface $backend) {}

    /**
     * Defines a named limiter: the callback returns the limit(s) for a request, or null for no limit.
     *
     * @param callable(Request): (Limit|list<Limit>|null) $callback
     */
    public function for(string $name, callable $callback): void
    {
        $this->limiters[$name] = $callback(...);
    }

    /** @return list<Limit> the limits of `$name` for this request (empty: unlimited) */
    public function limitsFor(string $name, Request $request): array
    {
        $callback = $this->limiters[$name] ?? throw new InvalidArgumentException("Rate limiter '{$name}' is not defined");
        $limits = $callback($request);

        return $limits === null ? [] : (is_array($limits) ? $limits : [$limits]);
    }

    public function has(string $name): bool
    {
        return isset($this->limiters[$name]);
    }

    /** Records a hit; the result says whether it was within the limit. */
    public function hit(string $key, int $maxAttempts, int $decaySeconds = 60): RateLimitResult
    {
        return $this->backend->hit($key, $maxAttempts, $decaySeconds);
    }

    /**
     * Runs `$callback` and counts it when the key is under its limit; returns false (without
     * running it) when over the limit, otherwise the callback's result (true when it returns null).
     *
     * @param callable(): mixed $callback
     */
    public function attempt(string $key, int $maxAttempts, callable $callback, int $decaySeconds = 60): mixed
    {
        if ($this->tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            return false;
        }
        $this->backend->hit($key, $maxAttempts, $decaySeconds);

        return $callback() ?? true;
    }

    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds = 60): bool
    {
        return $this->backend->attempts($key, $decaySeconds) >= $maxAttempts;
    }

    public function attempts(string $key, int $decaySeconds = 60): int
    {
        return $this->backend->attempts($key, $decaySeconds);
    }

    public function remaining(string $key, int $maxAttempts, int $decaySeconds = 60): int
    {
        return max(0, $maxAttempts - $this->backend->attempts($key, $decaySeconds));
    }

    /** Seconds until the key may try again. */
    public function availableIn(string $key, int $decaySeconds = 60): int
    {
        return $this->backend->availableIn($key, $decaySeconds);
    }

    public function clear(string $key, int $decaySeconds = 60): void
    {
        $this->backend->reset($key, $decaySeconds);
    }
}
