<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Shutdown;

use InvalidArgumentException;
use Omnitrix\Core\Runtime\Shutdown\ResourceRegistry;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

#[CoversClass(ResourceRegistry::class)]
final class ResourceRegistryTest extends TestCase
{
    public function testReleasesByPriorityThenReverseRegistrationAndCollectsFailures(): void
    {
        $registry = new ResourceRegistry();
        $order = [];
        $registry->register('logger', static function () use (&$order): void {
            $order[] = 'logger';
        }, 0);
        $registry->register('db', static function () use (&$order): void {
            $order[] = 'db';
        }, 50);
        $registry->register('http', static function () use (&$order): void {
            $order[] = 'http';
        }, 100);
        $registry->register('redis', static function (): void {
            throw new RuntimeException('redis close failed');
        }, 50);

        self::assertSame(['http', 'redis', 'db', 'logger'], $registry->names());
        $failures = $registry->releaseAll();

        self::assertSame(['http', 'db', 'logger'], $order);
        self::assertSame(['redis'], array_keys($failures));
        self::assertSame(0, $registry->count());
        self::assertSame([], $registry->releaseAll(), 'idempotent');
    }

    public function testRejectsDuplicateAndEmptyNames(): void
    {
        $registry = new ResourceRegistry();
        $registry->register('a', static fn() => null);
        try {
            $registry->register('a', static fn() => null);
            self::fail('duplicate accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("'a'", $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $registry->register('', static fn() => null);
    }

    public function testForgetDoesNotClose(): void
    {
        $registry = new ResourceRegistry();
        $closed = false;
        $registry->register('x', static function () use (&$closed): void {
            $closed = true;
        });
        $registry->forget('x');
        self::assertFalse($registry->has('x'));
        $registry->releaseAll();
        self::assertFalse($closed);
    }

    public function testReentrantReleaseFromACloserIsIgnored(): void
    {
        $registry = new ResourceRegistry();
        $calls = 0;
        $registry->register('x', static function () use (&$calls, $registry): void {
            $calls++;
            $registry->releaseAll();
        });
        $registry->releaseAll();
        self::assertSame(1, $calls);
    }
}
