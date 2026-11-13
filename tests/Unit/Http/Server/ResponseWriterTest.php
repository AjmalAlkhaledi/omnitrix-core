<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Http\Server;

use InvalidArgumentException;
use JsonException;
use Omnitrix\Core\Http\Server\HeadersAlreadySentException;
use Omnitrix\Core\Http\Server\ResponseWriter;
use Omnitrix\Core\Http\Status;
use Omnitrix\Core\Tests\Support\MemoryResponseSink;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function file_put_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(ResponseWriter::class)]
#[CoversClass(Status::class)]
#[CoversClass(HeadersAlreadySentException::class)]
final class ResponseWriterTest extends TestCase
{
    public function testJsonResponseSetsStatusHeadersAndBody(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink);
        $w->header('x-custom-header', 'v')->cookie('sid', 'abc', 0, '/', '', true, true, 'Strict');

        self::assertTrue($w->json(['a' => 1, 'u' => 'ü'], Status::CREATED));

        self::assertSame(201, $sink->status);
        self::assertSame('Created', $sink->reason);
        self::assertSame('v', $sink->headers['X-Custom-Header'], 'header names are canonicalised');
        self::assertSame('application/json', $sink->headers['Content-Type']);
        self::assertSame('{"a":1,"u":"ü"}', $sink->finalBody);
        self::assertSame(['name' => 'sid', 'value' => 'abc', 'expires' => 0, 'path' => '/', 'domain' => '', 'secure' => true, 'httpOnly' => true, 'sameSite' => 'Strict'], $sink->cookies[0]);
        self::assertTrue($w->isEnded());
        self::assertSame(16, $w->bytesWritten(), 'unicode is sent unescaped (2 bytes for ü)');
        self::assertFalse($w->end('again'), 'second end is ignored');
    }

    public function testStreamingCommitsHeadersOnFirstWriteAndFreezesThem(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink);
        $w->status(202)->header('Content-Type', 'text/plain');
        self::assertFalse($w->headersSent());
        self::assertTrue($w->write('a'));
        self::assertTrue($w->headersSent());
        self::assertTrue($w->write('b'));
        self::assertTrue($w->end('c'));
        self::assertSame(['a', 'b', 'c'], $sink->chunks, 'the final body is sent as a chunk once streaming started');
        self::assertSame('', $sink->finalBody);
        self::assertSame(202, $sink->status);
        self::assertSame(3, $w->bytesWritten());

        $w2 = new ResponseWriter(new MemoryResponseSink());
        $w2->write('x');
        $this->expectException(HeadersAlreadySentException::class);
        $w2->header('Late', '1');
    }

    public function testClientDisconnectMakesSubsequentSendsNoOps(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink);
        self::assertTrue($w->write('1'));
        $sink->clientGone = true;
        self::assertFalse($w->write('2'));
        self::assertTrue($w->clientGone());
        self::assertFalse($w->end('3'));
        self::assertTrue($w->isEnded());
        self::assertSame(1, $w->bytesWritten());
    }

    public function testHeadRequestsSendHeadersOnly(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink, headOnly: true);
        self::assertTrue($w->text('hello world'));
        self::assertSame('', $sink->finalBody);
        self::assertSame('11', $sink->headers['Content-Length']);
        self::assertSame(0, $w->bytesWritten());
    }

    public function testHelpersAndValidation(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink);
        $w->headers(['A' => '1', 'B' => '2'])->removeHeader('a');
        self::assertFalse($w->hasHeader('A'));
        self::assertSame('2', $w->getHeader('b'));
        self::assertSame(['B' => '2'], $w->getHeaders());
        self::assertTrue($w->redirect('/next', Status::SEE_OTHER));
        self::assertSame(303, $sink->status);
        self::assertSame('/next', $sink->headers['Location']);

        self::assertTrue((new ResponseWriter($s2 = new MemoryResponseSink()))->noContent());
        self::assertSame(204, $s2->status);

        $this->expectException(InvalidArgumentException::class);
        (new ResponseWriter(new MemoryResponseSink()))->status(99);
    }

    public function testJsonEncodingFailureThrowsBeforeAnythingIsSent(): void
    {
        $sink = new MemoryResponseSink();
        $w = new ResponseWriter($sink);
        try {
            $w->json("\xB1\x31");
            self::fail('invalid UTF-8 must not encode');
        } catch (JsonException) {
            self::assertFalse($w->headersSent());
            self::assertFalse($w->isEnded());
            self::assertNull($sink->status);
        }
    }

    public function testSendFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'omni');
        self::assertNotFalse($path);
        file_put_contents($path, 'data');
        try {
            $sink = new MemoryResponseSink();
            $w = new ResponseWriter($sink);
            $w->header('Content-Type', 'application/octet-stream');
            self::assertTrue($w->sendFile($path));
            self::assertSame($path, $sink->sentFile);
            self::assertTrue($w->isEnded());
            $this->expectException(InvalidArgumentException::class);
            (new ResponseWriter(new MemoryResponseSink()))->sendFile($path . '.missing');
        } finally {
            unlink($path);
        }
    }
}
