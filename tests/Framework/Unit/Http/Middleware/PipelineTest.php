<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Http\Middleware;

use Closure;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Middleware\Pipeline;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Pipeline::class)]
final class PipelineTest extends TestCase
{
    private static function request(): Request
    {
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse("GET /p HTTP/1.1\r\nHost: h\r\n\r\n");

        return new Request(new IncomingRequest($raw), RequestContext::create());
    }

    public function testMiddlewareRunsInOrderAroundTheHandler(): void
    {
        $log = [];
        $record = static function (string $entry) use (&$log): void {
            $log[] = $entry;
        };
        $class = new class ($record) implements MiddlewareInterface {
            /** @param Closure(string): void $record */
            public function __construct(private readonly Closure $record) {}

            public function process(Request $request, Next $next): Response
            {
                ($this->record)('class:before');
                $response = $next($request);
                ($this->record)('class:after');

                return $response->header('X-Class', '1');
            }
        };
        $closure = static function (Request $request, Next $next) use (&$log): Response {
            $log[] = 'closure:before';
            $request->setAttribute('from', 'closure');
            $response = $next($request);
            $log[] = 'closure:after';

            return $response->header('X-Closure', '1');
        };
        $handler = static function (Request $request) use (&$log): Response {
            $log[] = 'handler';

            return Response::text('body:' . self::string($request->attribute('from')));
        };

        $pipeline = Pipeline::compile([$class, $closure], $handler);
        $response = $pipeline(self::request());

        self::assertSame(['class:before', 'closure:before', 'handler', 'closure:after', 'class:after'], $log);
        self::assertSame('body:closure', $response->getBody());
        self::assertSame(['Content-Type' => 'text/plain; charset=utf-8', 'X-Closure' => '1', 'X-Class' => '1'], $response->getHeaders());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $handlerCalled = false;
        $pipeline = Pipeline::compile([
            static fn(Request $r, Next $next): Response => Response::json(['denied' => true], 403),
            static function (Request $r, Next $next) use (&$handlerCalled): Response {
                $handlerCalled = true;

                return $next($r);
            },
        ], static fn(Request $r): Response => Response::text('never'));

        $response = $pipeline(self::request());
        self::assertSame(403, $response->getStatus());
        self::assertFalse($handlerCalled);
    }

    public function testEmptyPipelineCallsHandlerDirectly(): void
    {
        $pipeline = Pipeline::compile([], static fn(Request $r): Response => Response::noContent());
        self::assertSame(204, $pipeline(self::request())->getStatus());
    }
}
