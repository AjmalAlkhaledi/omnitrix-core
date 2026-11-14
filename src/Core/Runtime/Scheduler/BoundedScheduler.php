<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Scheduler;

use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Throwable;

use function max;

/**
 * Admits at most `$limit` concurrently running coroutines (backpressure for application-level
 * fan-out). Callers wait up to `$acquireTimeout` seconds for a slot and otherwise receive a
 * {@see SchedulerSaturatedException}; nothing is queued without bound.
 *
 * Slots are a Channel of tokens: `pop()` acquires, `push()` releases, both O(1) and
 * coroutine-safe. Statistics are plain counters for the metrics endpoint.
 */
final class BoundedScheduler
{
    private readonly Channel $slots;

    private int $running = 0;

    private int $peak = 0;

    private int $rejected = 0;

    private int $completed = 0;

    /** @param int<1, max> $limit */
    public function __construct(
        public readonly int $limit,
        private readonly float $acquireTimeout,
        private readonly ExceptionHandlerInterface $exceptionHandler,
    ) {
        $this->slots = new Channel($limit);
        for ($i = 0; $i < $limit; $i++) {
            $this->slots->push(true);
        }
    }

    /**
     * Starts `$task` in a new coroutine once a slot is free. Returns the coroutine id.
     * `$onComplete` (if given) receives the task's exception or null.
     *
     * @param callable(): void $task
     * @param null|callable(?Throwable): void $onComplete
     *
     * @throws SchedulerSaturatedException
     */
    public function spawn(callable $task, ?callable $onComplete = null, ?float $acquireTimeout = null): int
    {
        $this->acquire($acquireTimeout ?? $this->acquireTimeout);
        $inherited = ContextStorage::current();

        $cid = Coroutine::create(function () use ($task, $onComplete, $inherited): void {
            if ($inherited !== null) {
                ContextStorage::bind($inherited);
            }
            $failure = null;
            try {
                $task();
            } catch (Throwable $e) {
                $failure = $e;
            } finally {
                $this->release();
            }
            $this->completed++;
            if ($onComplete !== null) {
                try {
                    $onComplete($failure);
                } catch (Throwable $e) {
                    $this->exceptionHandler->handle($e, ['origin' => 'scheduler.onComplete']);
                }
            } elseif ($failure !== null) {
                $this->exceptionHandler->handle($failure, ['origin' => 'scheduler']);
            }
        });

        if ($cid === false) {
            $this->release();
            throw new SchedulerSaturatedException('Coroutine creation failed (runtime max_coroutine reached)');
        }

        return $cid;
    }

    /** Runs `$task` inline in the current coroutine while holding a slot. */
    public function run(callable $task, ?float $acquireTimeout = null): mixed
    {
        $this->acquire($acquireTimeout ?? $this->acquireTimeout);
        try {
            return $task();
        } finally {
            $this->release();
        }
    }

    public function running(): int
    {
        return $this->running;
    }

    public function available(): int
    {
        return $this->slots->length();
    }

    /** @return array{limit: int, running: int, peak: int, rejected: int, completed: int} */
    public function stats(): array
    {
        return ['limit' => $this->limit, 'running' => $this->running, 'peak' => $this->peak, 'rejected' => $this->rejected, 'completed' => $this->completed];
    }

    private function acquire(float $timeout): void
    {
        if ($this->slots->pop(max(0.001, $timeout)) !== true) {
            $this->rejected++;
            throw new SchedulerSaturatedException("Scheduler saturated: {$this->limit} tasks running, no slot within {$timeout}s");
        }
        $this->running++;
        $this->peak = max($this->peak, $this->running);
    }

    private function release(): void
    {
        $this->running--;
        $this->slots->push(true);
    }
}
