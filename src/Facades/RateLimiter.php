<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\RateLimit\RateLimiter as Limiter;
use Omnitrix\RateLimit\RateLimitResult;

/**
 * Rate limiting (documentation: The basics → Security).
 *
 * ```php
 * if (RateLimiter::tooManyAttempts('login:' . $email, 5, 60)) { … RateLimiter::availableIn('login:' . $email) … }
 * RateLimiter::hit('login:' . $email, 5, 60);
 * RateLimiter::clear('login:' . $email);
 * ```
 */
final class RateLimiter extends Facade
{
    public static function limiter(): Limiter
    {
        return self::resolve(Limiter::class);
    }

    public static function hit(string $key, int $maxAttempts, int $decaySeconds = 60): RateLimitResult
    {
        return self::limiter()->hit($key, $maxAttempts, $decaySeconds);
    }

    /** @param callable(): mixed $callback */
    public static function attempt(string $key, int $maxAttempts, callable $callback, int $decaySeconds = 60): mixed
    {
        return self::limiter()->attempt($key, $maxAttempts, $callback, $decaySeconds);
    }

    public static function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds = 60): bool
    {
        return self::limiter()->tooManyAttempts($key, $maxAttempts, $decaySeconds);
    }

    public static function attempts(string $key, int $decaySeconds = 60): int
    {
        return self::limiter()->attempts($key, $decaySeconds);
    }

    public static function remaining(string $key, int $maxAttempts, int $decaySeconds = 60): int
    {
        return self::limiter()->remaining($key, $maxAttempts, $decaySeconds);
    }

    public static function availableIn(string $key, int $decaySeconds = 60): int
    {
        return self::limiter()->availableIn($key, $decaySeconds);
    }

    public static function clear(string $key, int $decaySeconds = 60): void
    {
        self::limiter()->clear($key, $decaySeconds);
    }
}
