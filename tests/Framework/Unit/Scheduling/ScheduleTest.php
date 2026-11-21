<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Scheduling;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Omnitrix\Scheduling\CronExpression;
use Omnitrix\Scheduling\Schedule;
use Omnitrix\Scheduling\ScheduledTask;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use RuntimeException;

use function microtime;
use function sort;

#[CoversClass(CronExpression::class)]
#[CoversClass(ScheduledTask::class)]
#[CoversClass(Schedule::class)]
final class ScheduleTest extends TestCase
{
    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function testCronExpressions(): void
    {
        self::assertTrue((new CronExpression('*/15 9-17 * * mon-fri'))->isDue(self::at('2026-11-23 09:30')), 'Monday 09:30');
        self::assertFalse((new CronExpression('*/15 9-17 * * mon-fri'))->isDue(self::at('2026-11-22 09:30')), 'Sunday');
        self::assertFalse((new CronExpression('*/15 9-17 * * 1-5'))->isDue(self::at('2026-11-23 09:31')));
        self::assertTrue((new CronExpression('0 0 * * 7'))->isDue(self::at('2026-11-22 00:00')), '7 is Sunday');
        self::assertTrue((new CronExpression('0 0 * * 1-7'))->isDue(self::at('2026-11-22 00:00')), 'ranges up to 7');
        self::assertTrue((new CronExpression('0 12 1 * mon'))->isDue(self::at('2026-11-23 12:00')), 'either day field matches when both are set');
        self::assertTrue((new CronExpression('0 12 1 * mon'))->isDue(self::at('2026-12-01 12:00')));
        self::assertTrue((new CronExpression('5,35 * * jan,nov *'))->isDue(self::at('2026-11-21 10:35')));
        self::assertSame('2026-11-23 00:00', (new CronExpression('0 0 * * mon'))->nextRunAfter(self::at('2026-11-21 15:10'))->format('Y-m-d H:i'));
        foreach (['* * * *', '60 * * * *', '* * * * 8', '*/0 * * * *', 'x * * * *', '5-1 * * * *'] as $bad) {
            try {
                new CronExpression($bad);
                self::fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                self::assertNotSame('', $bad);
            }
        }
    }

    public function testFrequenciesTimezonesAndConditions(): void
    {
        $schedule = new Schedule();
        $daily = $schedule->call(static fn() => null)->dailyAt('02:30');
        $weekdays = $schedule->call(static fn() => null)->hourly()->weekdays();
        $tokyo = $schedule->call(static fn() => null)->dailyAt('09:00')->timezone('Asia/Tokyo');
        $flag = false;
        $guarded = $schedule->call(static fn() => null)->everyMinute()->when(static function () use (&$flag): bool {
            return $flag;
        });
        $skipped = $schedule->call(static fn() => null)->everyMinute()->skip(static fn(): bool => true);
        self::assertSame('30 2 * * *', $daily->expression());
        self::assertSame('0 * * * 1-5', $weekdays->expression());
        self::assertTrue($daily->isDue(self::at('2026-11-21 02:30')));
        self::assertTrue($tokyo->isDue(self::at('2026-11-21 00:00')), '09:00 in Tokyo is 00:00 UTC');
        self::assertFalse($guarded->isDue(self::at('2026-11-21 02:30')));
        $flag = true;
        self::assertTrue($guarded->isDue(self::at('2026-11-21 02:30')));
        self::assertFalse($skipped->isDue(self::at('2026-11-21 02:30')));
        self::assertCount(2, $schedule->dueTasks(self::at('2026-11-21 02:30')));
        $this->expectException(InvalidArgumentException::class);
        $schedule->call(static fn() => null)->dailyAt('25:00');
    }

    public function testDueTasksRunConcurrentlyAndFailuresAreContained(): void
    {
        $schedule = new Schedule();
        $ran = [];
        $schedule->call(static function () use (&$ran): void {
            Coroutine::usleep(300_000);
            $ran[] = 'a';
        })->everyMinute();
        $schedule->call(static function () use (&$ran): void {
            Coroutine::usleep(300_000);
            $ran[] = 'b';
        })->everyMinute();
        $schedule->call(static fn() => throw new RuntimeException('boom'))->everyMinute();
        $schedule->call(static function () use (&$ran): void {
            $ran[] = 'never';
        })->hourlyAt(59);
        $t = microtime(true);
        self::assertSame(3, $schedule->runDue(self::at('2026-11-21 10:00'), null, new NullLogger()));
        self::assertLessThan(0.55, microtime(true) - $t, 'two 300 ms tasks overlap');
        sort($ran);
        self::assertSame(['a', 'b'], $ran, 'a failing task does not stop the others');
    }
}
