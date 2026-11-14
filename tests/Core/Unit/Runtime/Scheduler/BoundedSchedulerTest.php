<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Scheduler;

use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Scheduler\BoundedScheduler;
use Omnitrix\Core\Runtime\Scheduler\SchedulerSaturatedException;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use Throwable;

use function max;

#[CoversClass(BoundedScheduler::class)]
#[CoversClass(SchedulerSaturatedException::class)]
final class BoundedSchedulerTest extends TestCase
{
    public function testNeverRunsMoreThanTheLimitConcurrently(): void
    {
        $handler = new RecordingExceptionHandler();
        $scheduler = new BoundedScheduler(4, 5.0, $handler);
        $inFlight = 0;
        $observedPeak = 0;
        $done = new Channel(20);

        for ($i = 0; $i < 20; $i++) {
            $scheduler->spawn(static function () use (&$inFlight, &$observedPeak): void {
                $inFlight++;
                $observedPeak = max($observedPeak, $inFlight);
                Sleep::milliseconds(20);
                $inFlight--;
            }, static function () use ($done): void {
                $done->push(true);
            });
        }
        for ($i = 0; $i < 20; $i++) {
            $done->pop(5.0);
        }

        self::assertSame(4, $observedPeak);
        self::assertSame(4, $scheduler->stats()['peak']);
        self::assertSame(20, $scheduler->stats()['completed']);
        self::assertSame(0, $scheduler->running());
        self::assertSame(4, $scheduler->available());
        self::assertSame([], $handler->records);
    }

    public function testRejectsWhenSaturatedForLongerThanTheAcquireTimeout(): void
    {
        $scheduler = new BoundedScheduler(1, 0.05, new RecordingExceptionHandler());
        $release = new Channel(1);
        $scheduler->spawn(static function () use ($release): void {
            $release->pop(5.0);
        });

        try {
            $scheduler->spawn(static fn() => null);
            self::fail('expected saturation');
        } catch (SchedulerSaturatedException $e) {
            self::assertStringContainsString('1 tasks running', $e->getMessage());
        }
        self::assertSame(1, $scheduler->stats()['rejected']);

        $release->push(true);
        Sleep::milliseconds(10);
        self::assertSame(0, $scheduler->running());
    }

    public function testFailuresGoToOnCompleteOrTheExceptionHandler(): void
    {
        $handler = new RecordingExceptionHandler();
        $scheduler = new BoundedScheduler(2, 1.0, $handler);
        $seen = new Channel(1);

        $scheduler->spawn(static function (): void {
            throw new RuntimeException('a');
        }, static function (?Throwable $e) use ($seen): void {
            $seen->push($e?->getMessage());
        });
        self::assertSame('a', $seen->pop(1.0));

        $scheduler->spawn(static function (): void {
            throw new RuntimeException('b');
        });
        Sleep::milliseconds(20);
        self::assertCount(1, $handler->records);
        self::assertSame('b', $handler->records[0]['exception']->getMessage());
        self::assertSame(2, $scheduler->available(), 'slots released after failures');
    }

    public function testRunExecutesInlineWhileHoldingASlot(): void
    {
        $scheduler = new BoundedScheduler(1, 0.05, new RecordingExceptionHandler());
        $result = $scheduler->run(static function () use ($scheduler): string {
            self::assertSame(1, $scheduler->running());

            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertSame(0, $scheduler->running());
    }

    public function testTenThousandCoroutinesCompleteThroughTheScheduler(): void
    {
        $scheduler = new BoundedScheduler(500, 10.0, new RecordingExceptionHandler());
        $counter = 0;
        $done = new Channel(1);
        $n = 10_000;
        for ($i = 0; $i < $n; $i++) {
            $scheduler->spawn(static function () use (&$counter, $n, $done): void {
                Sleep::milliseconds(1);
                if (++$counter === $n) {
                    $done->push(true);
                }
            });
        }
        self::assertTrue($done->pop(30.0));
        Sleep::milliseconds(20); // the last task's bookkeeping runs after the channel hand-off
        self::assertSame($n, $scheduler->stats()['completed']);
        self::assertLessThanOrEqual(500, $scheduler->stats()['peak']);
    }
}
