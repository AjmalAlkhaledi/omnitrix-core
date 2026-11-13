<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Context;

use Omnitrix\Core\Runtime\Context\ContextNotAvailableException;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Coroutine\Coroutines;
use Omnitrix\Core\Tests\Support\RecordingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ContextStorage::class)]
final class ContextStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        ContextStorage::unbind();
        parent::tearDown();
    }

    public function testConcurrentCoroutinesNeverSeeEachOthersContext(): void
    {
        $tasks = [];
        for ($i = 0; $i < 50; $i++) {
            $tasks[$i] = static function () use ($i): array {
                $ctx = RequestContext::create();
                $ctx->set('n', $i);
                ContextStorage::bind($ctx);
                self::wait(0.01 + ($i % 5) * 0.005); // interleave suspensions
                $seen = ContextStorage::require();

                return [$seen->id === $ctx->id, $seen->get('n')];
            };
        }

        [$results] = self::runConcurrently($tasks);
        foreach ($results as $i => [$sameId, $n]) {
            self::assertTrue($sameId, "coroutine {$i} saw a foreign context");
            self::assertSame($i, $n);
        }
    }

    public function testChildAndGrandchildInheritTheParentContext(): void
    {
        $ctx = RequestContext::create();
        ContextStorage::bind($ctx);
        $out = new Channel(2);

        Coroutine::create(static function () use ($out): void {
            $out->push(['child', ContextStorage::current()?->id]);
            $grandchildDone = new Channel(1);
            Coroutine::create(static function () use ($out, $grandchildDone): void {
                self::wait(0.01);
                $out->push(['grandchild', ContextStorage::current()?->id]);
                $grandchildDone->push(true);
            });
            $grandchildDone->pop(1.0); // implicit inheritance only works while ancestors are alive
        });

        $first = $out->pop(1.0);
        $second = $out->pop(1.0);
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame($ctx->id, $first[1]);
        self::assertSame($ctx->id, $second[1]);
    }

    public function testFrameworkSpawnPropagatesContextEvenAfterTheSpawnerFinished(): void
    {
        $ctx = RequestContext::create();
        ContextStorage::bind($ctx);
        $out = new Channel(1);
        Coroutines::spawn(static function () use ($out): void {
            Coroutines::spawn(static function () use ($out): void {
                self::wait(0.03); // intermediate coroutine is gone by now
                $out->push(ContextStorage::current()?->id);
            }, new RecordingExceptionHandler());
        }, new RecordingExceptionHandler());

        self::assertSame($ctx->id, $out->pop(1.0));
    }

    public function testDetachedCoroutineLosesContextOnceTheParentFinished(): void
    {
        $out = new Channel(1);
        Coroutine::create(static function () use ($out): void {
            ContextStorage::bind(RequestContext::create());
            Coroutine::create(static function () use ($out): void {
                self::wait(0.03); // parent returns before we look
                $out->push(ContextStorage::current() === null);
            });
        });

        self::assertTrue($out->pop(1.0), 'context must not survive the request coroutine');
    }

    public function testRequireThrowsWithoutBinding(): void
    {
        ContextStorage::unbind();
        self::assertNull(ContextStorage::current());
        $this->expectException(ContextNotAvailableException::class);
        ContextStorage::require();
    }

    public function testUnbindRemovesOnlyTheBinding(): void
    {
        $ctx = RequestContext::create();
        ContextStorage::bind($ctx);
        self::assertSame($ctx, ContextStorage::current());
        ContextStorage::unbind();
        self::assertNull(ContextStorage::current());
        self::assertFalse($ctx->isDisposed());
    }
}
