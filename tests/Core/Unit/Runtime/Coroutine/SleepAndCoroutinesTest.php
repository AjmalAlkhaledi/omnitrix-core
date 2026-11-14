<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Coroutine;

use InvalidArgumentException;
use Omnitrix\Core\Runtime\Coroutine\Coroutines;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function hrtime;

#[CoversClass(Sleep::class)]
#[CoversClass(Coroutines::class)]
final class SleepAndCoroutinesTest extends TestCase
{
    public function testSleepHonoursSubSecondDurations(): void
    {
        $t = hrtime(true);
        Sleep::for(0.25);
        $elapsed = (hrtime(true) - $t) / 1e9;
        self::assertGreaterThanOrEqual(0.24, $elapsed, 'Co::sleep(float) would return immediately');
        self::assertLessThan(0.5, $elapsed);
    }

    public function testSleepsOverlapAcrossCoroutines(): void
    {
        [, $elapsed] = self::runConcurrently([
            static fn() => Sleep::milliseconds(200),
            static fn() => Sleep::milliseconds(200),
            static fn() => Sleep::milliseconds(200),
        ]);
        self::assertLessThan(0.4, $elapsed);
    }

    public function testSleepRejectsNegativeDuration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Sleep::for(-1);
    }

    public function testSpawnRoutesExceptionsToTheHandlerInsteadOfKillingTheWorker(): void
    {
        $handler = new RecordingExceptionHandler();

        $cid = Coroutines::spawn(static function (): void {
            Sleep::milliseconds(10);
            throw new RuntimeException('task failed');
        }, $handler, ['task' => 'demo']);

        self::assertGreaterThan(Coroutine::getCid(), $cid);
        Sleep::milliseconds(50);
        self::assertCount(1, $handler->records);
        self::assertSame('task failed', $handler->records[0]['exception']->getMessage());
        self::assertSame('demo', $handler->records[0]['context']['task']);
        self::assertSame('coroutine', $handler->records[0]['context']['origin']);
        self::assertSame($cid, $handler->records[0]['context']['cid']);
    }
}
