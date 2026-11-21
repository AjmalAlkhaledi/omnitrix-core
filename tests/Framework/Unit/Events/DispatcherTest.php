<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Events;

use Omnitrix\Core\Container\Container;
use Omnitrix\Events\Dispatcher;
use Omnitrix\Events\EventFake;
use Omnitrix\Events\ShouldDispatchAfterCommit;
use Omnitrix\Events\ShouldQueue;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\StoppableEventInterface;

interface ShippingEvent {}

class BaseEvent {}

final class OrderShipped extends BaseEvent implements ShippingEvent
{
    /** @var list<string> */
    public array $log = [];

    public function __construct(public int $order = 1) {}
}

final class Stoppable implements StoppableEventInterface
{
    public bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class Committed implements ShouldDispatchAfterCommit
{
    public function __construct(public string $name) {}
}

final class RecordShipment
{
    /** @var list<int> */
    public static array $handled = [];

    public function handle(OrderShipped $event): void
    {
        self::$handled[] = $event->order;
    }

    public function audit(OrderShipped $event): string
    {
        return 'audited ' . $event->order;
    }
}

final class InvokableListener
{
    public function __invoke(OrderShipped $event): string
    {
        return 'invoked';
    }
}

final class QueuedShipment implements ShouldQueue
{
    public string $queue = 'mail';

    public float $delay = 5.0;

    /** @var list<int> */
    public static array $handled = [];

    public function handle(OrderShipped $event): void
    {
        self::$handled[] = $event->order;
    }
}

#[CoversClass(Dispatcher::class)]
#[CoversClass(EventFake::class)]
final class DispatcherTest extends TestCase
{
    public function testPrioritiesHierarchyWildcardsAndStopping(): void
    {
        $events = new Dispatcher();
        $events->listen(OrderShipped::class, static function (OrderShipped $e): void {
            $e->log[] = 'class';
        });
        $events->listen(BaseEvent::class, static function (OrderShipped $e): void {
            $e->log[] = 'parent';
        });
        $events->listen(ShippingEvent::class, static function (OrderShipped $e): void {
            $e->log[] = 'interface';
        }, priority: 10);
        $event = new OrderShipped();
        $events->dispatch($event);
        self::assertSame(['interface', 'class', 'parent'], $event->log, 'higher priority first, then registration order');

        $events->listen('order.*', static fn(int $id): string => "wild {$id}");
        $events->listen('order.paid', static fn(int $id): string => "paid {$id}");
        self::assertSame(['paid 7', 'wild 7'], $events->dispatch('order.paid', [7]));
        self::assertTrue($events->hasListeners('order.refunded'), 'wildcards count');
        self::assertFalse($events->hasListeners('user.created'));

        $events->listen('stop', static fn(): bool => false);
        $events->listen('stop', static fn(): string => 'never');
        self::assertSame([], $events->dispatch('stop'), 'false stops the dispatch');
        $stoppable = new Stoppable();
        $events->listen(Stoppable::class, static function (Stoppable $e): string {
            $e->stopped = true;

            return 'first';
        });
        $events->listen(Stoppable::class, static fn(): string => 'second');
        self::assertSame(['first'], $events->dispatch($stoppable));

        $events->listen('ask', static fn(): ?string => null);
        $events->listen('ask', static fn(): string => 'answer');
        self::assertSame('answer', $events->until('ask'));
        $events->forget('ask');
        self::assertNull($events->until('ask'));
    }

    public function testClassListenersAndContainer(): void
    {
        RecordShipment::$handled = [];
        $container = new Container();
        $events = new Dispatcher($container);
        $events->listen(OrderShipped::class, RecordShipment::class);
        $events->listen(OrderShipped::class, RecordShipment::class . '@audit');
        $events->listen(OrderShipped::class, [RecordShipment::class, 'audit']);
        $events->listen(OrderShipped::class, InvokableListener::class);
        self::assertSame([null, 'audited 3', 'audited 3', 'invoked'], $events->dispatch(new OrderShipped(3)));
        self::assertSame([3], RecordShipment::$handled);
    }

    public function testQueuedListenersRunInTheWorker(): void
    {
        QueuedShipment::$handled = [];
        $jobs = [];
        $events = new Dispatcher(null, static function (string $job, array $payload, float $delay, ?string $queue) use (&$jobs): void {
            $jobs[] = [$job, $payload, $delay, $queue];
        });
        $events->listen(OrderShipped::class, QueuedShipment::class);
        $events->dispatch(new OrderShipped(9));
        self::assertSame([], QueuedShipment::$handled, 'not run during the request');
        self::assertCount(1, $jobs);
        self::assertSame([Dispatcher::QUEUED_LISTENER_JOB, 5.0, 'mail'], [$jobs[0][0], $jobs[0][2], $jobs[0][3]]);

        (new Dispatcher())->handleQueued($jobs[0][1]);
        self::assertSame([9], QueuedShipment::$handled, 'the worker runs the listener with the event');
    }

    public function testAfterCommitEventsWaitForTheTransaction(): void
    {
        $pending = [];
        $transaction = new \stdClass();
        $transaction->open = true;
        $events = new Dispatcher(null, null, static function (callable $callback) use (&$pending, $transaction): bool {
            if ($transaction->open !== true) {
                return false;
            }
            $pending[] = $callback;

            return true;
        });
        $seen = [];
        $events->listen(Committed::class, static function (Committed $e) use (&$seen): void {
            $seen[] = $e->name;
        });
        $events->dispatch(new Committed('a'));
        self::assertSame([], $seen, 'held until the commit');
        foreach ($pending as $callback) {
            $callback();
        }
        self::assertSame(['a'], $seen);
        $transaction->open = false;
        $events->dispatch(new Committed('b'));
        self::assertSame(['a', 'b'], $seen, 'outside a transaction it runs at once');
    }

    public function testTheFakeRecordsAndAsserts(): void
    {
        $real = new Dispatcher();
        $ran = false;
        $real->listen('other', static function () use (&$ran): void {
            $ran = true;
        });
        $fake = new EventFake($real, [OrderShipped::class]);
        $fake->dispatch(new OrderShipped(4));
        $fake->dispatch('other');
        self::assertTrue($ran, 'events not faked reach the real dispatcher');
        $fake->assertDispatched(OrderShipped::class);
        $fake->assertDispatched(OrderShipped::class, static fn(OrderShipped $e): bool => $e->order === 4, times: 1);
        $fake->assertNotDispatched(OrderShipped::class, static fn(OrderShipped $e): bool => $e->order === 5);
        $this->expectException(AssertionFailedError::class);
        $fake->assertNothingDispatched();
    }
}
