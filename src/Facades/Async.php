<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Concurrency\Async as Combinators;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\Outcome;
use Omnitrix\Core\Concurrency\TaskScope;

/**
 * Public entry point for structured concurrency (see docs/concurrency.md).
 *
 * ```php
 * $results = Async::all([
 *     'user'   => fn () => DB::table('users')->find(10),
 *     'remote' => fn () => Http::get('https://api.example.com/data')->json(),
 * ]);
 * ```
 */
final class Async
{
    private function __construct() {}

    /**
     * @template T
     * @param array<array-key, callable(CancellationToken): T> $tasks
     * @return array<array-key, T>
     */
    public static function all(array $tasks, ?float $timeout = null, ?int $concurrency = null): array
    {
        return Combinators::all($tasks, $timeout, $concurrency);
    }

    /**
     * @param array<array-key, callable(CancellationToken): mixed> $tasks
     * @return array<array-key, Outcome>
     */
    public static function allSettled(array $tasks, ?float $timeout = null, ?int $concurrency = null): array
    {
        return Combinators::allSettled($tasks, $timeout, $concurrency);
    }

    /**
     * @template T
     * @param array<array-key, callable(CancellationToken): T> $tasks
     * @return T
     */
    public static function race(array $tasks, ?float $timeout = null): mixed
    {
        return Combinators::race($tasks, $timeout);
    }

    /**
     * @template T
     * @param callable(CancellationToken): T $body
     * @return T
     */
    public static function withTimeout(callable $body, float $timeout): mixed
    {
        return Combinators::withTimeout($body, $timeout);
    }

    /**
     * @template T
     * @param callable(TaskScope): T $body
     * @return T
     */
    public static function scope(callable $body, ?int $concurrency = null): mixed
    {
        return Combinators::scope($body, $concurrency);
    }

    public static function sleep(float $seconds): void
    {
        Combinators::sleep($seconds);
    }
}
