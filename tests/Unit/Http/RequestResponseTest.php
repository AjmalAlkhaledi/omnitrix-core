<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Http;

use Generator;
use InvalidArgumentException;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Http\Server\ResponseWriter;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Http\Cookie;
use Omnitrix\Http\Exception\NotFoundException;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Http\ResponseEmitter;
use Omnitrix\Http\Stream;
use Omnitrix\Tests\Support\MemoryResponseSink;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;

use function file_put_contents;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(Request::class)]
#[CoversClass(Response::class)]
#[CoversClass(Cookie::class)]
#[CoversClass(ResponseEmitter::class)]
#[CoversClass(Stream::class)]
final class RequestResponseTest extends TestCase
{
    private static function request(string $raw): Request
    {
        $swoole = SwooleRequest::create(['parse_cookie' => true, 'parse_body' => true]);
        self::assertInstanceOf(SwooleRequest::class, $swoole);
        self::assertSame(strlen($raw), $swoole->parse($raw));

        return new Request(new IncomingRequest($swoole), RequestContext::create());
    }

    public function testRequestMergesInputSourcesAndExposesRouting(): void
    {
        $body = '{"name":"json","shared":"from-json"}';
        $r = self::request("POST /users/5?shared=from-query&q=1 HTTP/1.1\r\nHost: h\r\nAuthorization: Bearer tok-123\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        $r->setRoute(['id' => '5'], 'users.show');

        self::assertSame('POST', $r->method());
        self::assertSame('/users/5', $r->path());
        self::assertSame('from-json', $r->input('shared'), 'JSON wins over query');
        self::assertSame('1', $r->input('q'));
        self::assertSame('dflt', $r->input('missing', 'dflt'));
        self::assertSame(['shared' => 'from-json', 'q' => '1', 'name' => 'json'], $r->all());
        self::assertSame('tok-123', $r->bearerToken());
        self::assertSame('5', $r->route('id'));
        self::assertSame(5, $r->routeInt('id'));
        self::assertSame(['id' => '5'], $r->routeParams());
        self::assertSame('users.show', $r->routeName());
        self::assertSame(32, strlen($r->id()));
        self::assertSame($r->context()->id, $r->id());

        $r->setAttribute('user', ['id' => 1]);
        self::assertTrue($r->hasAttribute('user'));
        self::assertSame(['user' => ['id' => 1]], $r->attributes());
        self::assertNull($r->attribute('nope'));
    }

    public function testRouteIntRejectsNonNumericWith404(): void
    {
        $r = self::request("GET /x HTTP/1.1\r\nHost: h\r\n\r\n");
        $r->setRoute(['id' => '12abc'], null);
        self::assertNull($r->bearerToken());
        $this->expectException(NotFoundException::class);
        $r->routeInt('id');
    }

    public function testResponseBuildersAndEmitter(): void
    {
        $sink = new MemoryResponseSink();
        $response = Response::json(['ok' => true], 201)->header('x-extra', 'v')->cookie(new Cookie('sid', 'abc', secure: true, sameSite: 'None'));
        (new ResponseEmitter())->emit($response, new ResponseWriter($sink));
        self::assertSame(201, $sink->status);
        self::assertSame('application/json', $sink->headers['Content-Type']);
        self::assertSame('v', $sink->headers['X-Extra']);
        self::assertSame('{"ok":true}', $sink->finalBody);
        self::assertSame('sid', $sink->cookies[0]['name']);
        self::assertSame('None', $sink->cookies[0]['sameSite']);

        self::assertSame('text/plain; charset=utf-8', Response::text('t')->getHeader('content-type'));
        self::assertSame('text/html; charset=utf-8', Response::html('<p>')->getHeader('Content-Type'));
        self::assertSame(204, Response::noContent()->getStatus());
        self::assertSame('/x', Response::redirect('/x', 301)->getHeader('Location'));
        self::assertNull(Response::text('t')->withoutHeader('Content-Type')->getHeader('Content-Type'));
        self::assertSame(Response::BODY_STRING, Response::text('t')->bodyType());

        $forget = Cookie::forget('sid');
        self::assertSame(1, $forget->expires);
        self::assertSame('', $forget->value);
    }

    public function testStreamingAndChunkedResponsesAreEmittedProgressively(): void
    {
        $sink = new MemoryResponseSink();
        $stream = Response::stream(static function (Stream $w): void {
            $w->write('a');
            $w->write('b');
        }, 200, 'text/event-stream');
        (new ResponseEmitter())->emit($stream, new ResponseWriter($sink));
        self::assertSame(['a', 'b'], $sink->chunks);
        self::assertTrue($sink->ended);
        self::assertSame('text/event-stream', $sink->headers['Content-Type']);

        $sink = new MemoryResponseSink();
        $gen = (static function (): Generator {
            yield 'x';
            yield 'y';
        })();
        (new ResponseEmitter())->emit(Response::chunks($gen), new ResponseWriter($sink));
        self::assertSame(['x', 'y'], $sink->chunks);

        $sink = new MemoryResponseSink();
        $sink->clientGone = true;
        $yielded = 0;
        $gen = (static function () use (&$yielded): Generator {
            for ($i = 0; $i < 5; $i++) {
                $yielded++;
                yield 'c';
            }
        })();
        (new ResponseEmitter())->emit(Response::chunks($gen), new ResponseWriter($sink));
        self::assertSame(1, $yielded, 'iteration stops once the client is gone');
    }

    public function testFileResponseAndValidation(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'omni');
        self::assertNotFalse($path);
        file_put_contents($path, 'f');
        try {
            $sink = new MemoryResponseSink();
            (new ResponseEmitter())->emit(Response::file($path, 'text/plain', 'a"b.txt'), new ResponseWriter($sink));
            self::assertSame($path, $sink->sentFile);
            self::assertSame('attachment; filename="a\"b.txt"', $sink->headers['Content-Disposition']);
        } finally {
            unlink($path);
        }
        try {
            Response::file('/nonexistent/file');
            self::fail('missing file accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            Response::redirect('/x', 200);
            self::fail('non-3xx redirect accepted');
        } catch (InvalidArgumentException) {
        }
        try {
            new Cookie('bad name', 'v');
            self::fail('invalid cookie name accepted');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new Cookie('n', 'v', sameSite: 'None');
    }
}
