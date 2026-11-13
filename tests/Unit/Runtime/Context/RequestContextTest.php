<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Context;

use InvalidArgumentException;
use Omnitrix\Core\Runtime\Context\ContextNotAvailableException;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;

use function microtime;
use function strlen;

#[CoversClass(RequestContext::class)]
final class RequestContextTest extends TestCase
{
    public function testCreateAssignsUniqueIdsAndTracksElapsedTime(): void
    {
        $a = RequestContext::create();
        $b = RequestContext::create();
        self::assertNotSame($a->id, $b->id);
        self::assertSame(32, strlen($a->id));
        self::wait(0.05);
        self::assertGreaterThanOrEqual(0.04, $a->elapsed(), 'timer granularity is ~1 ms');
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RequestContext('');
    }

    public function testAttributesAreIsolatedPerInstance(): void
    {
        $a = RequestContext::create();
        $b = RequestContext::create();
        $a->set('user', 'alice');
        self::assertSame('alice', $a->get('user'));
        self::assertFalse($b->has('user'));
        self::assertSame('anon', $b->get('user', 'anon'));
        $a->remove('user');
        self::assertSame([], $a->all());
    }

    public function testDeferredCallbacksRunOnceInLifoOrderAndCollectFailures(): void
    {
        $ctx = RequestContext::create();
        $order = [];
        $ctx->defer(static function () use (&$order): void {
            $order[] = 'first';
        });
        $ctx->defer(static function (): void {
            throw new RuntimeException('second failed');
        });
        $ctx->defer(static function () use (&$order): void {
            $order[] = 'third';
        });

        $failures = $ctx->dispose();

        self::assertSame(['third', 'first'], $order);
        self::assertCount(1, $failures);
        self::assertSame('second failed', $failures[0]->getMessage());
        self::assertTrue($ctx->isDisposed());
        self::assertSame([], $ctx->dispose(), 'second dispose is a no-op');
        self::assertSame([], $ctx->all(), 'attributes cleared on dispose');
    }

    public function testDisposedContextRejectsMutation(): void
    {
        $ctx = RequestContext::create();
        $ctx->dispose();
        $this->expectException(ContextNotAvailableException::class);
        $ctx->set('x', 1);
    }

    public function testDeadlineReportsRemainingTimeAndExpiry(): void
    {
        $ctx = RequestContext::create();
        self::assertNull($ctx->remainingTime());
        self::assertFalse($ctx->isExpired());
        $ctx->setDeadline(microtime(true) + 0.05);
        $remaining = $ctx->remainingTime();
        self::assertNotNull($remaining);
        self::assertGreaterThan(0.0, $remaining);
        self::wait(0.07);
        self::assertTrue($ctx->isExpired());
    }
}
