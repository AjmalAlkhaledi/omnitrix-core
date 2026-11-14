<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Worker;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Runtime\Shutdown\ResourceRegistry;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use Omnitrix\Core\Runtime\Worker\WorkerLifecycle;
use Omnitrix\Core\Runtime\Worker\WorkerRole;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(WorkerLifecycle::class)]
#[CoversClass(WorkerContext::class)]
#[CoversClass(WorkerRole::class)]
final class WorkerLifecycleTest extends TestCase
{
    public function testStartInOrderStopInReverseThenResourcesReleasedAndFailuresReported(): void
    {
        $handler = new RecordingExceptionHandler();
        $lifecycle = new WorkerLifecycle($handler);
        $resources = new ResourceRegistry();
        $context = new WorkerContext(3, 123, WorkerRole::Http, new Container(), $resources, null);
        $events = [];

        $lifecycle->onStart(static function (WorkerContext $c) use (&$events, $resources): void {
            $events[] = 'start1:' . $c->workerId;
            $resources->register('pool', static function () use (&$events): void {
                $events[] = 'release:pool';
            });
        });
        $lifecycle->onStart(static function (): void {
            throw new RuntimeException('start2 failed');
        });
        $lifecycle->onStart(static function () use (&$events): void {
            $events[] = 'start3';
        });
        $lifecycle->onStop(static function () use (&$events): void {
            $events[] = 'stop1';
        });
        $lifecycle->onStop(static function () use (&$events): void {
            $events[] = 'stop2';
        });

        self::assertSame(1, $lifecycle->start($context));
        self::assertSame(0, $lifecycle->stop($context));

        self::assertSame(['start1:3', 'start3', 'stop2', 'stop1', 'release:pool'], $events);
        self::assertCount(1, $handler->records);
        self::assertSame('worker.start', $handler->records[0]['context']['origin']);
        self::assertSame(0, $resources->count());
    }
}
