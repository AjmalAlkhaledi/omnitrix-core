<?php

declare(strict_types=1);

namespace Omnitrix\Events;

use Closure;
use InvalidArgumentException;
use Omnitrix\Contracts\EventDispatcher;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use ReflectionClass;

use function array_merge;
use function array_values;
use function base64_decode;
use function base64_encode;
use function class_exists;
use function class_implements;
use function class_parents;
use function explode;
use function fnmatch;
use function get_class;
use function is_array;
use function is_callable;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function is_subclass_of;
use function krsort;
use function method_exists;
use function serialize;
use function str_contains;
use function unserialize;

/**
 * Event dispatcher. Events are objects (listened to by class, parent class or interface) or
 * names with arguments (`dispatch('order.shipped', [$order])`); names may be listened to with
 * wildcards (`order.*`, `*`). Listeners run in priority order (higher first, then registration
 * order); a listener returning `false` — or a PSR-14 stoppable event that is stopped — ends
 * the dispatch.
 *
 * Listeners are callables, classes with `handle()` (or `__invoke()`), `Class@method` strings or
 * `[Class::class, 'method']`. Classes are taken from the container when it has them. Listener
 * classes implementing {@see ShouldQueue} are queued; events implementing
 * {@see ShouldDispatchAfterCommit} wait for the current database transaction to commit.
 */
final class Dispatcher implements EventDispatcher
{
    /** Job name the queue worker handles for queued listeners. */
    public const string QUEUED_LISTENER_JOB = 'omnitrix.listener';

    /** @var array<string, array<int, list<callable|string|array{class-string, string}>>> */
    private array $listeners = [];

    /** @var array<string, array<int, list<callable|string|array{class-string, string}>>> */
    private array $wildcards = [];

    /**
     * @param (Closure(string, array<string, mixed>, float, ?string): mixed)|null $queue    dispatches a job (name, payload, delay, queue)
     * @param (Closure(callable): bool)|null                                    $deferred runs a callback after the current commit; false when no transaction is open
     * @param (Closure(\Omnitrix\Broadcasting\ShouldBroadcast): void)|null       $broadcast publishes broadcastable events
     */
    public function __construct(
        private readonly ?ContainerInterface $container = null,
        private readonly ?Closure $queue = null,
        private readonly ?Closure $deferred = null,
        private readonly ?Closure $broadcast = null,
    ) {}

    public function listen(string|array $events, callable|string|array $listener, int $priority = 0): void
    {
        foreach ((array) $events as $event) {
            if (str_contains($event, '*')) {
                $this->wildcards[$event][$priority][] = $listener;
            } else {
                $this->listeners[$event][$priority][] = $listener;
            }
        }
    }

    /** Removes every listener of an event name or class. */
    public function forget(string $event): void
    {
        unset($this->listeners[$event], $this->wildcards[$event]);
    }

    public function hasListeners(string $event): bool
    {
        if (isset($this->listeners[$event])) {
            return true;
        }
        foreach ($this->wildcards as $pattern => $_) {
            if (fnmatch($pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    public function dispatch(object|string $event, array $payload = []): array
    {
        return $this->fire($event, $payload, halt: false);
    }

    public function until(object|string $event, array $payload = []): mixed
    {
        $results = $this->fire($event, $payload, halt: true);

        return $results[0] ?? null;
    }

    /**
     * Runs a queued listener in the worker (the handler of {@see QUEUED_LISTENER_JOB}).
     *
     * @param array<string, mixed> $payload
     */
    public function handleQueued(array $payload): void
    {
        $listener = $payload['listener'] ?? null;
        $method = $payload['method'] ?? 'handle';
        $event = is_string($payload['event'] ?? null) ? base64_decode($payload['event'], true) : false;
        if (!is_string($listener) || !class_exists($listener) || !is_string($method) || !is_string($event)) {
            throw new InvalidArgumentException('Invalid queued listener payload');
        }
        /** @var list<mixed> $arguments */
        $arguments = unserialize($event); // written by this application's dispatcher only
        $callable = [$this->make($listener), $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException("Listener {$listener} has no {$method}() method");
        }
        $callable(...$arguments);
    }

    /**
     * @param list<mixed> $payload
     *
     * @return list<mixed>
     */
    private function fire(object|string $event, array $payload, bool $halt): array
    {
        if (is_object($event) && $event instanceof ShouldDispatchAfterCommit && $this->deferred !== null) {
            $deferred = ($this->deferred)(function () use ($event, $payload, $halt): void {
                $this->fire(new AfterCommitted($event), $payload, $halt);
            });
            if ($deferred) {
                return [];
            }
        }
        if ($event instanceof AfterCommitted) {
            $event = $event->event;
        }
        if ($event instanceof \Omnitrix\Broadcasting\ShouldBroadcast && $this->broadcast !== null) {
            ($this->broadcast)($event);
        }
        $name = is_object($event) ? get_class($event) : $event;
        $arguments = is_object($event) ? [$event] : $payload;
        $results = [];
        foreach ($this->listenersFor($event, $name) as $listener) {
            $result = $this->call($listener, $arguments);
            if ($halt && $result !== null) {
                return [$result];
            }
            if ($result === false) {
                break;
            }
            $results[] = $result;
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
        }

        return $halt ? [] : $results;
    }

    /** @return list<callable|string|array{class-string, string}> */
    private function listenersFor(object|string $event, string $name): array
    {
        $names = [$name];
        if (is_object($event)) {
            $parents = class_parents($event);
            $interfaces = class_implements($event);
            $names = array_merge($names, $parents === false ? [] : array_values($parents), $interfaces === false ? [] : array_values($interfaces));
        }
        $byPriority = [];
        foreach ($names as $candidate) {
            foreach ($this->listeners[$candidate] ?? [] as $priority => $listeners) {
                foreach ($listeners as $listener) {
                    $byPriority[$priority][] = $listener;
                }
            }
        }
        foreach ($this->wildcards as $pattern => $priorities) {
            if (fnmatch($pattern, $name)) {
                foreach ($priorities as $priority => $listeners) {
                    foreach ($listeners as $listener) {
                        $byPriority[$priority][] = $listener;
                    }
                }
            }
        }
        krsort($byPriority);
        $ordered = [];
        foreach ($byPriority as $listeners) {
            foreach ($listeners as $listener) {
                $ordered[] = $listener;
            }
        }

        return $ordered;
    }

    /**
     * @param callable|string|array{class-string, string} $listener
     * @param list<mixed>                                  $arguments
     */
    private function call(callable|string|array $listener, array $arguments): mixed
    {
        [$class, $method] = self::target($listener);
        if ($class === null) {
            if (!is_callable($listener)) {
                throw new InvalidArgumentException('Listener is not callable');
            }

            return $listener(...$arguments);
        }
        if ($this->queue !== null && is_subclass_of($class, ShouldQueue::class)) {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $queue = is_string($defaults['queue'] ?? null) ? $defaults['queue'] : null;
            $delay = $defaults['delay'] ?? 0;
            ($this->queue)(self::QUEUED_LISTENER_JOB, ['listener' => $class, 'method' => $method, 'event' => base64_encode(serialize($arguments))], is_int($delay) || is_float($delay) ? (float) $delay : 0.0, $queue);

            return null;
        }
        $callable = [$this->make($class), $method];
        if (!is_callable($callable)) {
            throw new InvalidArgumentException("Listener {$class} has no {$method}() method");
        }

        return $callable(...$arguments);
    }

    /**
     * Class and method of a class-based listener; [null, ''] for other callables.
     *
     * @param callable|string|array{class-string, string} $listener
     *
     * @return array{class-string|null, string}
     */
    private static function target(callable|string|array $listener): array
    {
        if (is_string($listener)) {
            if (str_contains($listener, '@')) {
                [$class, $method] = explode('@', $listener, 2);
                if (class_exists($class)) {
                    return [$class, $method];
                }
            } elseif (class_exists($listener)) {
                return [$listener, method_exists($listener, 'handle') ? 'handle' : '__invoke'];
            }

            return [null, ''];
        }
        if (is_array($listener) && is_string($listener[0] ?? null) && class_exists($listener[0]) && is_string($listener[1] ?? null)) {
            return [$listener[0], $listener[1]];
        }

        return [null, ''];
    }

    /** @param class-string $class */
    private function make(string $class): object
    {
        if ($this->container !== null && $this->container->has($class)) {
            $instance = $this->container->get($class);
            if (is_object($instance)) {
                return $instance;
            }
        }

        return new $class();
    }
}
