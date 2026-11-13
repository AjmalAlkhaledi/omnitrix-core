<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Container;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Container\ContainerException;
use Omnitrix\Core\Container\NotFoundException;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;

#[CoversClass(Container::class)]
#[CoversClass(ContainerException::class)]
#[CoversClass(NotFoundException::class)]
final class ContainerTest extends TestCase
{
    public function testBindCreatesNewInstancesAndSingletonCaches(): void
    {
        $c = new Container();
        $c->bind('fresh', static fn(): stdClass => new stdClass());
        $c->singleton('shared', static fn(): stdClass => new stdClass());
        $c->instance('inst', 42);

        self::assertNotSame($c->get('fresh'), $c->get('fresh'));
        self::assertSame($c->get('shared'), $c->get('shared'));
        self::assertSame(42, $c->get('inst'));
        self::assertTrue($c->has('shared'));
        self::assertFalse($c->has('nope'));
        self::assertSame(['fresh', 'shared', 'inst'], $c->ids());
    }

    public function testFactoriesReceiveTheContainerAndTypedAccessChecksClass(): void
    {
        $c = new Container();
        $c->singleton('dep', static fn(): stdClass => new stdClass());
        $c->singleton('svc', static fn(Container $c): array => ['dep' => $c->get('dep')]);
        self::assertSame($c->get('dep'), self::assoc($c->get('svc'))['dep']);
        self::assertInstanceOf(stdClass::class, $c->getTyped('dep', stdClass::class));
        $this->expectException(ContainerException::class);
        $c->getTyped('svc', stdClass::class);
    }

    public function testMissingEntryIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        (new Container())->get('missing');
    }

    public function testCircularDependencyIsDetected(): void
    {
        $c = new Container();
        $c->singleton('a', static fn(Container $c) => $c->get('b'));
        $c->singleton('b', static fn(Container $c) => $c->get('a'));
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular dependency');
        $c->get('a');
    }

    public function testFactoryFailureIsWrappedWithTheOriginalAsPrevious(): void
    {
        $c = new Container();
        $c->bind('bad', static function (): never {
            throw new RuntimeException('nope');
        });
        try {
            $c->get('bad');
            self::fail('expected failure');
        } catch (ContainerException $e) {
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertFalse($c->has('nothing'));
        }
    }
}
