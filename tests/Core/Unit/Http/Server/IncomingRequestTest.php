<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Http\Server;

use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Http\Server\RequestRejectedException;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;

use function str_repeat;
use function strlen;

#[CoversClass(IncomingRequest::class)]
final class IncomingRequestTest extends TestCase
{
    private static function parse(string $raw, int $depth = 64): IncomingRequest
    {
        $request = Request::create(['parse_cookie' => true, 'parse_body' => true]);
        self::assertInstanceOf(Request::class, $request);
        self::assertSame(strlen($raw), $request->parse($raw), 'parser must consume the whole fixture');
        self::assertTrue($request->isCompleted(), 'fixture request must be complete');

        return new IncomingRequest($request, $depth);
    }

    private static function post(string $body, string $contentType = 'application/json', string $extra = ''): string
    {
        return "POST /api/users?x=1&tags[]=a&tags[]=b HTTP/1.1\r\nHost: localhost\r\nContent-Type: {$contentType}\r\n{$extra}Content-Length: " . strlen($body) . "\r\n\r\n{$body}";
    }

    public function testExposesMethodPathQueryHeadersAndCookies(): void
    {
        $r = self::parse("GET /a/b%20c?x=1&y[]=2&y[]=3 HTTP/1.1\r\nHost: h\r\nX-Multi: one\r\nCookie: s=1; t=two\r\nAccept: */*\r\n\r\n");

        self::assertSame('GET', $r->method());
        self::assertTrue($r->isMethod('get'));
        self::assertSame('/a/b%20c', $r->path());
        self::assertSame('x=1&y[]=2&y[]=3', $r->queryString());
        self::assertSame(['x' => '1', 'y' => ['2', '3']], $r->query());
        self::assertSame('1', $r->queryParam('x'));
        self::assertSame('dflt', $r->queryParam('missing', 'dflt'));
        self::assertSame('1.1', $r->protocolVersion());
        self::assertSame('one', $r->header('x-multi'));
        self::assertSame('one', $r->header('X-MULTI'));
        self::assertTrue($r->hasHeader('Accept'));
        self::assertNull($r->header('nope'));
        self::assertSame(['s' => '1', 't' => 'two'], $r->cookies());
        self::assertSame('two', $r->cookie('t'));
        self::assertNull($r->contentType());
        self::assertFalse($r->isJson());
        self::assertSame('', $r->body());
        self::assertNull($r->json());
    }

    public function testDecodesJsonOnceWithObjectsAsArrays(): void
    {
        $r = self::parse(self::post('{"name":"n","nested":{"a":[1,2]},"big":12345678901234567890}', 'application/json; charset=utf-8'));

        self::assertSame('application/json', $r->contentType());
        self::assertTrue($r->isJson());
        self::assertSame(strlen('{"name":"n","nested":{"a":[1,2]},"big":12345678901234567890}'), $r->declaredContentLength());
        self::assertSame($r->declaredContentLength(), $r->bodyLength());
        $json = $r->jsonObject();
        self::assertSame('n', $json['name']);
        self::assertSame(['a' => [1, 2]], $json['nested']);
        self::assertSame('12345678901234567890', $json['big'], 'big integers must not lose precision');
        self::assertSame($json, $r->jsonObject(), 'decoded once and cached');
    }

    public function testRejectsMalformedJsonWith400(): void
    {
        $r = self::parse(self::post('{"a":'));
        $this->expectException(RequestRejectedException::class);
        $this->expectExceptionMessage('Malformed JSON body');
        $r->json();
    }

    public function testRejectsExcessiveNesting(): void
    {
        $r = self::parse(self::post(str_repeat('[', 10) . str_repeat(']', 10)), 4);
        $this->expectException(RequestRejectedException::class);
        $r->json();
    }

    public function testJsonObjectRejectsScalarsAndLists(): void
    {
        self::assertSame([1, 2], self::parse(self::post('[1,2]'))->json());
        try {
            self::parse(self::post('[1,2]'))->jsonObject();
            self::fail('list accepted as object');
        } catch (RequestRejectedException $e) {
            self::assertStringContainsString('must be an object', $e->getMessage());
        }
        $this->expectException(RequestRejectedException::class);
        self::parse(self::post('"scalar"'))->jsonObject();
    }

    public function testParsesFormBodiesAndVendorJsonTypes(): void
    {
        $form = self::parse(self::post('a=1&b[]=2', 'application/x-www-form-urlencoded'));
        self::assertSame(['a' => '1', 'b' => ['2']], $form->form());
        self::assertFalse($form->isJson());

        $vendor = self::parse(self::post('{"ok":true}', 'application/vnd.api+json'));
        self::assertTrue($vendor->isJson());
        self::assertSame(['ok' => true], $vendor->json());
    }
}
