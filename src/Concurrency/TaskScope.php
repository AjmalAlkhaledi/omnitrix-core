<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use LogicException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

use function count;
use function hrtime;
use function max;
use function microtime;

/**
 * Structured concurrency scope: every task spawned here is joined or cancelled before the
 * scope closes, so no child outlives its parent. Use through {@see Async::scope()} or
 * manually with try/finally around {@see close()}.
 */
final class TaskScope
{
    /** @var array<string|int, Task> */
    private array $tasks = [];

    private readonly CancellationToken $token;

    private readonly ?Semaphore $semaphore;

    private bool $closed = false;

    private int $sequence = 0;

    /** @var Channel settle events (Task instances) in completion order */
    private readonly Channel $settled;

    private int $settledCount = 0;

    /**
     * @param int|null $concurrency maximum tasks running at once (null = unbounded)
     */
    public function __construct(?CancellationToken $parent = null, ?int $concurrency = null)
    {
        $this->token = $parent === null ? new CancellationToken() : $parent->createChild();
        $this->semaphore = $concurrency === null ? null : new Semaphore($concurrency);
        $this->settled = new Channel(1024);
    }

    public function token(): CancellationToken
    {
        return $this->token;
    }

    /**
     * Starts a task. `$body` receives the scope's cancellation token.
     *
     * @param callable(CancellationToken): mixed $body
     */
    public function spawn(callable $body, string|int|null $key = null): Task
    {
        if ($this->closed) {
            throw new LogicException('Cannot spawn into a closed scope');
        }
        $key ??= $this->sequence++;
        if (isset($this->tasks[$key])) {
            throw new LogicException("A task with key '{$key}' already exists in this scope");
        }
        $this->tasks[$key] = Task::start($key, $body, $this->token, $this->semaphore, function (Task $task): void {
            $this->settled->push($task);
        });

        return $this->tasks[$key];
    }

    /**
     * Returns the next task to settle (completion order), or null when every spawned task
     * has already been returned once or the timeout passes. Lets callers react to the first
     * failure immediately instead of awaiting tasks in spawn order.
     */
    public function nextSettled(?float $timeout = null): ?Task
    {
        if ($this->settledCount >= count($this->tasks)) {
            return null;
        }
        $task = $this->settled->pop($timeout === null ? -1 : max(0.001, $timeout));
        if (!$task instanceof Task) {
            if (Coroutine::isCanceled()) {
                throw new CancelledException('Awaiting coroutine was cancelled');
            }

            return null;
        }
        $this->settledCount++;

        return $task;
    }

    /** @return array<string|int, Task> */
    public function tasks(): array
    {
        return $this->tasks;
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    /** Cancels every task (idempotent). */
    public function cancel(?string $reason = null): void
    {
        $this->token->cancel($reason);
    }

    /**
     * Waits for all tasks to settle (success, failure or cancellation). Returns false if the
     * timeout passed before every task settled.
     */
    public function join(?float $timeout = null): bool
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        foreach ($this->tasks as $task) {
            $remaining = $deadline === null ? null : max(0.001, $deadline - microtime(true));
            if ($deadline !== null && $deadline - microtime(true) <= 0.0 && !$task->isDone()) {
                return false;
            }
            if (!$task->join($remaining)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Closes the scope: cancels unfinished tasks and waits for them to unwind. After this no
     * child coroutine of the scope is running. Idempotent.
     *
     * @return int number of tasks that were still running and had to be cancelled
     */
    public function close(float $graceSeconds = 5.0): int
    {
        if ($this->closed) {
            return 0;
        }
        $this->closed = true;
        $pending = 0;
        foreach ($this->tasks as $task) {
            if (!$task->isDone()) {
                $pending++;
            }
        }
        if ($pending > 0) {
            $this->token->cancel('scope closed');
            $start = hrtime(true);
            foreach ($this->tasks as $task) {
                $left = $graceSeconds - (hrtime(true) - $start) / 1e9;
                $task->join(max(0.001, $left));
            }
        }

        return $pending;
    }

    /**
     * Coroutine ids of tasks that are still running (diagnostics).
     *
     * @return list<int>
     */
    public function running(): array
    {
        $ids = [];
        foreach ($this->tasks as $task) {
            if (!$task->isDone() && Coroutine::exists($task->coroutineId())) {
                $ids[] = $task->coroutineId();
            }
        }

        return $ids;
    }
}
