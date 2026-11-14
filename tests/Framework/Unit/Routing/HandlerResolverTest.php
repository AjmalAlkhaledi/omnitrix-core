<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Routing;

use InvalidArgumentException;
use Omnitrix\Core\Container\Container;
use Omnitrix\Http\Request;
use Omnitrix\Routing\HandlerResolver;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function json_encode;

#[CoversClass(HandlerResolver::class)]
final class HandlerResolverTest extends TestCase
{
    public function testResolvesClosuresArraysStringsAndInvokables(): void
    {
        $container = new Container();
        $container->singleton(ResolverFixtureController::class, static fn(): ResolverFixtureController => new ResolverFixtureController('from-container'));
        $resolver = new HandlerResolver($container);

        $closure = static fn(Request $r): string => 'closure';
        self::assertSame($closure, $resolver->resolve($closure));
        self::assertSame('from-container:show', ($resolver->resolve([ResolverFixtureController::class, 'show']))(self::dummyRequest()));
        self::assertSame('from-container:show', ($resolver->resolve(ResolverFixtureController::class . '::show'))(self::dummyRequest()));
        self::assertSame('new:__invoke', ($resolver->resolve(ResolverFixtureInvokable::class))(self::dummyRequest()));
        self::assertSame('instance:show', ($resolver->resolve([new ResolverFixtureController('instance'), 'show']))(self::dummyRequest()));
        self::assertSame('new:show', ((new HandlerResolver())->resolve([ResolverFixtureController::class, 'show']))(self::dummyRequest()), 'without a container the class is instantiated directly');
    }

    public function testRejectsUnknownHandlers(): void
    {
        $resolver = new HandlerResolver();
        $rejected = 0;
        foreach ([[ResolverFixtureController::class, 'missing'], 'NoSuchClass::x', 'NoSuchClass', 42, [1, 2, 3]] as $handler) {
            try {
                $resolver->resolve($handler);
                self::fail('accepted invalid handler ' . json_encode($handler));
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }
        self::assertSame(5, $rejected);
    }

    private static function dummyRequest(): Request
    {
        $raw = \OpenSwoole\Http\Request::create([]);
        self::assertInstanceOf(\OpenSwoole\Http\Request::class, $raw);
        $raw->parse("GET / HTTP/1.1\r\nHost: h\r\n\r\n");

        return new Request(new \Omnitrix\Core\Http\Server\IncomingRequest($raw), \Omnitrix\Core\Runtime\Context\RequestContext::create());
    }
}

final class ResolverFixtureController
{
    public function __construct(private readonly string $origin = 'new') {}

    public function show(Request $request): string
    {
        return $this->origin . ':show';
    }
}

final class ResolverFixtureInvokable
{
    public function __invoke(Request $request): string
    {
        return 'new:__invoke';
    }
}
