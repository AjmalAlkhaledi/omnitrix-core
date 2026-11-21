<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Omnitrix\Contracts\EventDispatcher;
use Omnitrix\Core\Container\Container;
use Omnitrix\Events\EventFake;

/**
 * Events (documentation: The basics → Events).
 *
 * ```php
 * Event::listen(OrderShipped::class, SendShipmentEmail::class);
 * Event::dispatch(new OrderShipped($order));
 * Event::fake(); ... Event::fake()->assertDispatched(OrderShipped::class);
 * ```
 *
 * Listeners added here exist in the current worker only; register application listeners with
 * `$app->listen()` in `bootstrap.php` or under `events.listen` in configuration.
 */
final class Event extends Facade
{
    public static function dispatcher(): EventDispatcher
    {
        return self::resolve(EventDispatcher::class);
    }

    /**
     * @param string|list<string>                         $events
     * @param callable|string|array{class-string, string} $listener
     */
    public static function listen(string|array $events, callable|string|array $listener, int $priority = 0): void
    {
        self::dispatcher()->listen($events, $listener, $priority);
    }

    /**
     * @param list<mixed> $payload
     *
     * @return list<mixed>
     */
    public static function dispatch(object|string $event, array $payload = []): array
    {
        return self::dispatcher()->dispatch($event, $payload);
    }

    /** @param list<mixed> $payload */
    public static function until(object|string $event, array $payload = []): mixed
    {
        return self::dispatcher()->until($event, $payload);
    }

    public static function hasListeners(string $event): bool
    {
        return self::dispatcher()->hasListeners($event);
    }

    /**
     * Replaces the dispatcher of this worker with a recording fake (tests).
     *
     * @param list<string> $only events to fake; empty fakes every event
     */
    public static function fake(array $only = []): EventFake
    {
        $container = self::container();
        if (!$container instanceof Container) {
            throw new LogicException('Event::fake() needs the application container (inside withServices())');
        }
        $current = $container->has(EventDispatcher::class) ? $container->get(EventDispatcher::class) : null;
        if ($current instanceof EventFake) {
            return $current;
        }
        $fake = new EventFake($current instanceof EventDispatcher ? $current : null, $only);
        $container->instance(EventDispatcher::class, $fake);

        return $fake;
    }
}
