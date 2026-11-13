<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Foundation;

use LogicException;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Proves the test harness itself: tests run inside a coroutine with hooks enabled, hooked
 * sleeps overlap, child exceptions surface as test failures, and the leak detector sees
 * finished children as reaped.
 */
#[CoversNothing]
final class CoroutineHarnessTest extends TestCase
{
    public function testRunsInsideRootCoroutineWithAllHooksEnabled(): void
    {
        self::assertGreaterThan(0, Coroutine::getCid());
        self::assertSame(Runtime::HOOK_ALL, Runtime::getHookFlags());
        self::assertSame(Runtime::HOOK_NATIVE_CURL, Runtime::HOOK_ALL & Runtime::HOOK_NATIVE_CURL, 'native curl hook is part of HOOK_ALL');
        self::assertSame(0, Runtime::HOOK_ALL & Runtime::HOOK_SOCKETS, 'ext-sockets hook stays off');
    }

    public function testHookedSleepsOverlapInsteadOfSerialising(): void
    {
        [$results, $elapsed] = self::runConcurrently([
            'a' => static function (): string {
                self::wait(0.15);
                return 'a';
            },
            'b' => static function (): string {
                self::wait(0.15);
                return 'b';
            },
            'c' => static function (): string {
                self::wait(0.15);
                return 'c';
            },
        ]);

        self::assertSame(['a' => 'a', 'b' => 'b', 'c' => 'c'], $results);
        self::assertLessThan(0.30, $elapsed, 'three 150 ms sleeps must overlap (serial would be 450 ms)');
        self::assertGreaterThanOrEqual(0.14, $elapsed);
    }

    public function testExceptionInsideChildCoroutineIsRethrownAfterAllChildrenFinish(): void
    {
        $finished = 0;

        try {
            self::runConcurrently([
                static function () use (&$finished): void {
                    self::wait(0.05);
                    $finished++;
                },
                static function (): void {
                    throw new LogicException('child failed');
                },
                static function () use (&$finished): void {
                    self::wait(0.05);
                    $finished++;
                },
            ]);
            self::fail('exception from the child coroutine was swallowed');
        } catch (LogicException $e) {
            self::assertSame('child failed', $e->getMessage());
        }

        self::assertSame(2, $finished, 'siblings ran to completion before the failure was rethrown');
    }

    public function testLeakDetectorSeesJoinedChildrenAsReaped(): void
    {
        $before = self::liveCoroutines();
        self::runConcurrently([static fn(): int => 1, static fn(): int => 2]);
        self::wait(0.001);
        self::assertSame($before, self::liveCoroutines());
    }
}
