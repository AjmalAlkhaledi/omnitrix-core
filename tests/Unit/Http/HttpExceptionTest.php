<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Http;

use InvalidArgumentException;
use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Http\Exception\HttpException;
use Omnitrix\Http\Exception\PayloadTooLargeException;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(HttpException::class)]
#[CoversClass(PayloadTooLargeException::class)]
final class HttpExceptionTest extends TestCase
{
    public function testHttpExceptionCarriesStatusHeadersAndDetails(): void
    {
        $e = new PayloadTooLargeException(details: ['max_body_size' => 10]);
        self::assertInstanceOf(HttpErrorInterface::class, $e, 'rendered by the transport like any HTTP error');
        self::assertSame(413, $e->status());
        self::assertSame('Request body exceeds the allowed size', $e->getMessage());
        self::assertSame(['max_body_size' => 10], $e->details());
        self::assertSame([], $e->headers());

        $generic = new HttpException(429, '', ['Retry-After' => '3']);
        self::assertSame('Too Many Requests', $generic->getMessage());
        self::assertSame(['Retry-After' => '3'], $generic->headers());

        $this->expectException(InvalidArgumentException::class);
        new HttpException(200);
    }
}
