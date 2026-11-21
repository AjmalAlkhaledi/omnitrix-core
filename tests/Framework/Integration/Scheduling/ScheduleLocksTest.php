<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Scheduling;

use DateTimeImmutable;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Scheduling\Schedule;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Throwable;

use function uniqid;

#[CoversClass(Schedule::class)]
final class ScheduleLocksTest extends TestCase
{
    public function testOneServerAndNoOverlapLocks(): void
    {
        $redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'schedule-' . uniqid() . ':']));
        try {
            $count = 0;
            $define = static function (Schedule $s) use (&$count): void {
                $s->call(static function () use (&$count): void {
                    $count++;
                })->everyMinute()->onOneServer()->description('report');
            };
            $serverA = new Schedule();
            $serverB = new Schedule();
            $define($serverA);
            $define($serverB);
            $minute = new DateTimeImmutable('2026-11-21 10:00');
            $serverA->runDue($minute, $redis, new NullLogger());
            $serverB->runDue($minute, $redis, new NullLogger());
            self::assertSame(1, $count, 'only one server runs it in a minute');
            $serverB->runDue($minute->modify('+1 minute'), $redis, new NullLogger());
            self::assertSame(2, $count, 'and again the next minute');

            $runs = 0;
            $slow = new Schedule();
            $slow->call(static function () use (&$runs): void {
                $runs++;
                Coroutine::usleep(400_000);
            })->everyMinute()->withoutOverlapping();
            $first = null;
            Coroutine::create(static function () use ($slow, $redis, &$first): void {
                try {
                    $first = $slow->runDue(new DateTimeImmutable('2026-11-21 11:00'), $redis, new NullLogger());
                } catch (Throwable $e) {
                    $first = $e::class . ': ' . $e->getMessage();
                }
            });
            Coroutine::usleep(100_000);
            self::assertSame(0, $slow->runDue(new DateTimeImmutable('2026-11-21 11:01'), $redis, new NullLogger()), 'skipped while the previous run is going');
            for ($i = 0; $i < 60 && $first === null; $i++) {
                Coroutine::usleep(50_000);
            }
            self::assertSame(1, $first);
            self::assertSame(1, $slow->runDue(new DateTimeImmutable('2026-11-21 11:02'), $redis, new NullLogger()), 'the lock is released afterwards');
            self::assertSame(2, $runs);
        } finally {
            $redis->close();
        }
    }
}
