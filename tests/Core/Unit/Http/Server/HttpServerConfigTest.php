<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Http\Server;

use Omnitrix\Core\Http\Server\HttpServerConfig;
use Omnitrix\Core\Http\Server\RequestRejectedException;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(HttpServerConfig::class)]
#[CoversClass(RequestRejectedException::class)]
final class HttpServerConfigTest extends TestCase
{
    public function testDefaults(): void
    {
        $c = HttpServerConfig::defaults();
        self::assertSame(1024 * 1024, $c->maxBodySize);
        self::assertSame(30.0, $c->requestTimeout);
        self::assertSame(64, $c->jsonMaxDepth);
        self::assertSame('X-Request-Id', $c->requestIdHeader);
        $custom = HttpServerConfig::fromArray(['max_body_size' => 512, 'request_timeout' => 2, 'request_id_header' => null]);
        self::assertSame(512, $custom->maxBodySize);
        self::assertSame(2.0, $custom->requestTimeout, 'integer seconds are accepted');
        self::assertNull($custom->requestIdHeader, 'explicit null disables the header');
        self::assertSame(2.5, HttpServerConfig::fromArray(['request_timeout' => 2.5])->requestTimeout);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown' => [['max_body' => 1], 'http.max_body'];
        yield 'body too large for parser' => [['max_body_size' => 3 * 1024 * 1024], 'http.max_body_size'];
        yield 'body zero' => [['max_body_size' => 0], 'http.max_body_size'];
        yield 'timeout negative' => [['request_timeout' => -1.0], 'http.request_timeout'];
        yield 'depth zero' => [['json_max_depth' => 0], 'http.json_max_depth'];
        yield 'bad header token' => [['request_id_header' => 'X Request'], 'http.request_id_header'];
        yield 'flags type' => [['json_flags' => 'x'], 'http.json_flags'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalid')]
    public function testRejectsInvalid(array $input, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("'{$path}'");
        HttpServerConfig::fromArray($input);
    }

    public function testRequestRejectionsCarryStatusAndDetails(): void
    {
        $e = RequestRejectedException::payloadTooLarge(10);
        self::assertSame(413, $e->status());
        self::assertSame('Request body exceeds the allowed size', $e->getMessage());
        self::assertSame(['max_body_size' => 10], $e->details());
        self::assertSame([], $e->headers());

        $bad = RequestRejectedException::badRequest('Malformed JSON body');
        self::assertSame(400, $bad->status());
        self::assertSame(400, $bad->getCode());
    }
}
