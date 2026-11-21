<?php

declare(strict_types=1);

namespace Omnitrix\Events;

use Omnitrix\Contracts\EventDispatcher;
use PHPUnit\Framework\Assert;

use function count;
use function get_class;
use function in_array;
use function is_object;

/**
 * Test double installed by `Event::fake()`: records dispatched events instead of running
 * listeners. `Event::fake([OrderShipped::class])` fakes only those events and passes the
 * others to the real dispatcher.
 */
final class EventFake implements EventDispatcher
{
    /** @var list<array{string, object|null, list<mixed>}> */
    private array $dispatched = [];

    /** @param list<string> $only events to fake; empty fakes all */
    public function __construct(private readonly ?EventDispatcher $real = null, private readonly array $only = []) {}

    public function listen(string|array $events, callable|string|array $listener, int $priority = 0): void
    {
        $this->real?->listen($events, $listener, $priority);
    }

    public function dispatch(object|string $event, array $payload = []): array
    {
        $name = is_object($event) ? get_class($event) : $event;
        if ($this->only !== [] && !in_array($name, $this->only, true)) {
            return $this->real?->dispatch($event, $payload) ?? [];
        }
        $this->dispatched[] = [$name, is_object($event) ? $event : null, $payload];

        return [];
    }

    public function until(object|string $event, array $payload = []): mixed
    {
        $this->dispatch($event, $payload);

        return null;
    }

    public function hasListeners(string $event): bool
    {
        return $this->real?->hasListeners($event) ?? false;
    }

    /**
     * Dispatches of `$event`, optionally filtered (`fn (OrderShipped $e) => $e->order->id === 1`).
     *
     * @return list<object|list<mixed>>
     */
    public function dispatched(string $event, ?callable $filter = null): array
    {
        $matches = [];
        foreach ($this->dispatched as [$name, $object, $payload]) {
            if ($name !== $event) {
                continue;
            }
            $subject = $object ?? $payload;
            if ($filter === null || ($object !== null ? $filter($object) : $filter(...$payload)) === true) {
                $matches[] = $subject;
            }
        }

        return $matches;
    }

    public function assertDispatched(string $event, ?callable $filter = null, ?int $times = null): void
    {
        $count = count($this->dispatched($event, $filter));
        Assert::assertTrue($times === null ? $count > 0 : $count === $times, "Event {$event} was dispatched {$count} time(s)" . ($times === null ? '' : ", expected {$times}"));
    }

    public function assertNotDispatched(string $event, ?callable $filter = null): void
    {
        Assert::assertSame([], $this->dispatched($event, $filter), "Event {$event} was dispatched");
    }

    public function assertNothingDispatched(): void
    {
        Assert::assertSame([], $this->dispatched, 'Events were dispatched');
    }
}
