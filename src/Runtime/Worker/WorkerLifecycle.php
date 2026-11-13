<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Worker;

use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Throwable;

use function array_reverse;

/**
 * Ordered listener lists for worker lifecycle events.
 *
 * Start listeners run in registration order; stop listeners in reverse, after which the
 * worker's {@see \Omnitrix\Core\Runtime\Shutdown\ResourceRegistry} is released. A listener that
 * throws is reported to the exception handler and does not prevent the others from running:
 * a half-initialised worker is still torn down completely.
 *
 * @internal
 */
final class WorkerLifecycle
{
    /** @var list<callable(WorkerContext): void> */
    private array $onStart = [];

    /** @var list<callable(WorkerContext): void> */
    private array $onStop = [];

    public function __construct(private readonly ExceptionHandlerInterface $exceptionHandler) {}

    /** @param callable(WorkerContext): void $listener */
    public function onStart(callable $listener): void
    {
        $this->onStart[] = $listener;
    }

    /** @param callable(WorkerContext): void $listener */
    public function onStop(callable $listener): void
    {
        $this->onStop[] = $listener;
    }

    /** @return int number of listeners that failed */
    public function start(WorkerContext $context): int
    {
        return $this->dispatch($this->onStart, $context, 'worker.start');
    }

    /** Runs stop listeners (reverse order), then releases registered resources. */
    public function stop(WorkerContext $context): int
    {
        $failures = $this->dispatch(array_reverse($this->onStop), $context, 'worker.stop');
        foreach ($context->resources->releaseAll() as $name => $e) {
            $failures++;
            $this->exceptionHandler->handle($e, ['origin' => 'worker.resources', 'resource' => $name, 'worker_id' => $context->workerId]);
        }

        return $failures;
    }

    /** @param list<callable(WorkerContext): void> $listeners */
    private function dispatch(array $listeners, WorkerContext $context, string $origin): int
    {
        $failures = 0;
        foreach ($listeners as $listener) {
            try {
                $listener($context);
            } catch (Throwable $e) {
                $failures++;
                $this->exceptionHandler->handle($e, ['origin' => $origin, 'worker_id' => $context->workerId, 'pid' => $context->pid]);
            }
        }

        return $failures;
    }
}
