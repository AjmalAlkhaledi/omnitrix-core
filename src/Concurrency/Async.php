<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use InvalidArgumentException;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use Throwable;

use function count;
use function max;
use function round;
use function str_starts_with;

/**
 * Structured concurrency combinators over {@see TaskScope}.
 *
 * All functions run inside the current coroutine, start one coroutine per task, and never
 * return while a child is still running: on failure or timeout the remaining tasks are
 * cancelled and awaited first. Keys and order of the returned arrays follow the input.
 */
final class Async
{
    private function __construct() {}

    /**
     * Runs all callables concurrently and returns their results keyed like `$tasks`.
     * Fail-fast: the first exception cancels the others and is rethrown after they unwind.
     *
     * @template T
     * @param array<array-key, callable(CancellationToken): T> $tasks
     * @param float|null $timeout     seconds for the whole group; {@see TimeoutException} on expiry
     * @param int|null   $concurrency maximum tasks running at once
     * @return array<array-key, T>
     */
    public static function all(array $tasks, ?float $timeout = null, ?int $concurrency = null, ?CancellationToken $parent = null): array
    {
        $scope = new TaskScope($parent, $concurrency);
        $timer = self::armTimeout($scope, $timeout);
        try {
            foreach ($tasks as $key => $task) {
                $scope->spawn($task, $key);
            }
            // Observe tasks in completion order so the first failure is acted on immediately.
            while (($settled = $scope->nextSettled()) !== null) {
                if ($settled->state() !== TaskState::Completed) {
                    $settled->await(); // throws the failure / cancellation
                }
            }
            $results = [];
            foreach ($scope->tasks() as $key => $task) {
                $results[$key] = $task->result();
            }

            return $results;
        } catch (Throwable $e) {
            $scope->cancel('sibling failed: ' . $e::class);
            throw self::translate($e, $scope, $timeout);
        } finally {
            if ($timer !== null) {
                Timers::clear($timer);
            }
            $scope->close();
        }
    }

    /**
     * Runs all callables concurrently and reports every outcome; individual failures do not
     * abort the group. A timeout cancels the still-running tasks, which are reported as
     * cancelled outcomes.
     *
     * @param array<array-key, callable(CancellationToken): mixed> $tasks
     * @return array<array-key, Outcome>
     */
    public static function allSettled(array $tasks, ?float $timeout = null, ?int $concurrency = null, ?CancellationToken $parent = null): array
    {
        $scope = new TaskScope($parent, $concurrency);
        $timer = self::armTimeout($scope, $timeout);
        try {
            foreach ($tasks as $key => $task) {
                $scope->spawn($task, $key);
            }
            $outcomes = [];
            foreach ($scope->tasks() as $key => $task) {
                $task->join();
                $outcomes[$key] = match ($task->state()) {
                    TaskState::Completed => Outcome::fulfilled($task->result()),
                    TaskState::Cancelled => Outcome::rejected($task->failure() ?? new CancelledException("Task '{$key}' was cancelled"), true),
                    default => Outcome::rejected($task->failure() ?? new CancelledException("Task '{$key}' failed")),
                };
            }

            return $outcomes;
        } finally {
            if ($timer !== null) {
                Timers::clear($timer);
            }
            $scope->close();
        }
    }

    /**
     * Returns the result of the first task to complete successfully and cancels the rest.
     * If every task fails, the last failure is thrown.
     *
     * @template T
     * @param array<array-key, callable(CancellationToken): T> $tasks
     * @return T
     */
    public static function race(array $tasks, ?float $timeout = null, ?CancellationToken $parent = null): mixed
    {
        if (count($tasks) === 0) {
            throw new InvalidArgumentException('race() needs at least one task');
        }
        $scope = new TaskScope($parent);
        $timer = self::armTimeout($scope, $timeout);
        try {
            foreach ($tasks as $key => $task) {
                $scope->spawn($task, $key);
            }
            $lastFailure = null;
            while (($settled = $scope->nextSettled()) !== null) {
                if ($settled->state() === TaskState::Completed) {
                    $scope->cancel('race won by ' . $settled->key);

                    return $settled->result();
                }
                $lastFailure = $settled->failure();
            }
            throw self::translate($lastFailure ?? new CancelledException('every task failed'), $scope, $timeout);
        } finally {
            if ($timer !== null) {
                Timers::clear($timer);
            }
            $scope->close();
        }
    }

    /**
     * Runs `$body` in a child task bounded by `$timeout` seconds; on expiry the task is
     * cancelled and {@see TimeoutException} is thrown.
     *
     * @template T
     * @param callable(CancellationToken): T $body
     * @return T
     */
    public static function withTimeout(callable $body, float $timeout, ?CancellationToken $parent = null): mixed
    {
        $results = self::all(['task' => $body], $timeout, null, $parent);

        return $results['task'];
    }

    /**
     * Runs `$body` with a scope that is closed (remaining tasks cancelled and awaited) when
     * the body returns or throws.
     *
     * @template T
     * @param callable(TaskScope): T $body
     * @return T
     */
    public static function scope(callable $body, ?int $concurrency = null, ?CancellationToken $parent = null): mixed
    {
        $scope = new TaskScope($parent, $concurrency);
        try {
            return $body($scope);
        } catch (Throwable $e) {
            $scope->cancel('scope body failed');
            throw $e;
        } finally {
            $scope->close();
        }
    }

    /** Cooperative, cancellable sleep. */
    public static function sleep(float $seconds): void
    {
        Sleep::for($seconds);
    }

    private static function armTimeout(TaskScope $scope, ?float $timeout): ?int
    {
        if ($timeout === null) {
            return null;
        }
        if ($timeout <= 0.0) {
            throw new InvalidArgumentException('timeout must be > 0 seconds');
        }
        return Timers::after(max(1, (int) round($timeout * 1000)), static function () use ($scope, $timeout): void {
            $scope->cancel("timeout after {$timeout}s");
        });
    }

    private static function translate(Throwable $e, TaskScope $scope, ?float $timeout): Throwable
    {
        $reason = $scope->token()->reason();
        if ($timeout !== null && $reason !== null && str_starts_with($reason, 'timeout after')) {
            return new TimeoutException("Operation timed out after {$timeout}s", 0, $e);
        }

        return $e;
    }
}
