<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Exception;

use Throwable;

/**
 * Receives every exception the runtime catches at a coroutine boundary (request handlers,
 * spawned tasks, timers, lifecycle hooks). Implementations must never throw.
 */
interface ExceptionHandlerInterface
{
    /** @param array<string, mixed> $context diagnostic data (request id, worker id, origin…) */
    public function handle(Throwable $exception, array $context = []): void;
}
