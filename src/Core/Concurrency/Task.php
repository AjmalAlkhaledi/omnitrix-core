<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use Closure;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use RuntimeException;
use Throwable;

use function max;

/**
 * A unit of concurrent work running in its own coroutine, owned by a {@see TaskScope}.
 *
 * The body runs with the spawner's request context bound and its coroutine attached to the
 * task's cancellation token. Completion is signalled through a one-slot channel so any number
 * of awaiters can join. Exceptions are stored, never thrown inside the task coroutine (which
 * would be fatal for the worker).
 */
final class Task
{
    private TaskState $state = TaskState::Pending;

    private mixed $result = null;

    private ?Throwable $failure = null;

    private readonly Channel $done;

    private int $cid = 0;

    /**
     * @param Closure(CancellationToken): mixed $body
     * @param null|Closure(Task): void           $onSettle called once when the task settles
     */
    private function __construct(
        public readonly string|int $key,
        private readonly Closure $body,
        private readonly CancellationToken $token,
        private readonly ?Semaphore $semaphore,
        private readonly ?Closure $onSettle,
    ) {
        $this->done = new Channel(1);
    }

    /**
     * Starts the task immediately (the coroutine is created now; it runs until its first
     * suspension before control returns to the caller). The task gets its own child of
     * `$parentToken`, so cancelling the task does not cancel its siblings.
     *
     * @param callable(CancellationToken): mixed $body
     * @param null|callable(Task): void           $onSettle
     */
    public static function start(string|int $key, callable $body, CancellationToken $parentToken, ?Semaphore $semaphore = null, ?callable $onSettle = null): self
    {
        $task = new self($key, $body(...), $parentToken->createChild(), $semaphore, $onSettle === null ? null : $onSettle(...));
        $task->launch();

        return $task;
    }

    private function launch(): void
    {
        $context = ContextStorage::current();
        $cid = Coroutine::create(function () use ($context): void {
            if ($context !== null) {
                ContextStorage::bind($context);
            }
            $this->cid = Coroutine::getCid();
            $permit = false;
            try {
                if ($this->semaphore !== null) {
                    $this->semaphore->acquire(null, $this->token);
                    $permit = true;
                }
                $this->token->throwIfCancelled();
                $this->state = TaskState::Running;
                $this->token->attach($this->cid);
                $this->result = ($this->body)($this->token);
                $this->state = TaskState::Completed;
            } catch (Throwable $e) {
                $this->failure = $e;
                // An interrupted operation surfaces as a library-specific error; if our token was
                // cancelled, the task is "cancelled", whatever the exception type.
                $this->state = $this->token->isCancelled() || $e instanceof CancelledException ? TaskState::Cancelled : TaskState::Failed;
            } finally {
                $this->token->detach($this->cid);
                if ($permit && $this->semaphore !== null) {
                    $this->semaphore->release();
                }
                $this->settle();
            }
        });
        if ($cid === false) {
            $this->failure = new RuntimeException('Could not create a coroutine for the task (max_coroutine reached?)');
            $this->state = TaskState::Failed;
            $this->settle();
        }
    }

    private function settle(): void
    {
        $this->done->push(true);
        if ($this->onSettle !== null) {
            ($this->onSettle)($this);
        }
    }

    public function state(): TaskState
    {
        return $this->state;
    }

    public function isDone(): bool
    {
        return $this->state === TaskState::Completed || $this->state === TaskState::Failed || $this->state === TaskState::Cancelled;
    }

    public function token(): CancellationToken
    {
        return $this->token;
    }

    public function coroutineId(): int
    {
        return $this->cid;
    }

    /** Requests cancellation; the task settles as Cancelled once its coroutine unwinds. */
    public function cancel(?string $reason = null): void
    {
        $this->token->cancel($reason);
    }

    /**
     * Waits for completion and returns the result; rethrows the task's failure, throws
     * {@see CancelledException} for cancelled tasks and {@see TimeoutException} when `$timeout`
     * (seconds) elapses first (the task keeps running in that case).
     */
    public function await(?float $timeout = null): mixed
    {
        $this->join($timeout);

        return match ($this->state) {
            TaskState::Completed => $this->result,
            TaskState::Cancelled => throw ($this->failure instanceof CancelledException ? $this->failure : new CancelledException("Task '{$this->key}' was cancelled" . ($this->token->reason() !== null ? ': ' . $this->token->reason() : ''), 0, $this->failure)),
            default => throw $this->failure ?? new RuntimeException("Task '{$this->key}' failed without an exception"),
        };
    }

    /** Waits for the task to settle without throwing its failure. Returns false on timeout. */
    public function join(?float $timeout = null): bool
    {
        if ($this->isDone()) {
            return true;
        }
        $signal = $this->done->pop($timeout === null ? -1 : max(0.001, $timeout));
        if ($signal === false) {
            if (Coroutine::isCanceled()) {
                throw new CancelledException('Awaiting coroutine was cancelled');
            }

            return false;
        }
        $this->done->push(true); // re-arm for other awaiters

        return true;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }
}
