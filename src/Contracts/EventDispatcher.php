<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

/** Dispatches events to their listeners. */
interface EventDispatcher
{
    /**
     * @param string|list<string>                                  $events   class names, names or wildcards (`order.*`)
     * @param callable|class-string|string|array{class-string, string} $listener
     */
    public function listen(string|array $events, callable|string|array $listener, int $priority = 0): void;

    /**
     * Calls every listener of `$event` (an object, or a name with `$payload` arguments) and
     * returns their results. A listener returning `false` stops the remaining ones.
     *
     * @param list<mixed> $payload
     *
     * @return list<mixed>
     */
    public function dispatch(object|string $event, array $payload = []): array;

    /**
     * Like dispatch(), but returns the first result that is not null (or null).
     *
     * @param list<mixed> $payload
     */
    public function until(object|string $event, array $payload = []): mixed;

    public function hasListeners(string $event): bool;
}
