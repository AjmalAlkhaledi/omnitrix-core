<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\HttpClient;

use Omnitrix\Core\HttpClient\Exception\ConnectionException;
use Omnitrix\Core\HttpClient\Exception\DnsException;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\Exception\TlsException;
use Omnitrix\Core\HttpClient\Transport\ErrorMapper;
use Omnitrix\Core\HttpClient\Uri;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ErrorMapper::class)]
final class ErrorMapperTest extends TestCase
{
    /** @return iterable<string, array{int, int, class-string}> */
    public static function codes(): iterable
    {
        yield 'timeout errno' => [110, -1, TimeoutException::class];
        yield 'timeout status' => [0, -2, TimeoutException::class];
        yield 'dns resolve failed' => [711, -1, DnsException::class];
        yield 'dns resolve timeout' => [712, -1, DnsException::class];
        yield 'tls verify' => [1014, -1, TlsException::class];
        yield 'refused' => [111, -1, ConnectionException::class];
        yield 'reset' => [104, -3, ConnectionException::class];
        yield 'server closed' => [0, -3, ConnectionException::class];
    }

    /** @param class-string $expected */
    #[DataProvider('codes')]
    public function testMapsOpenSwooleCodes(int $errCode, int $status, string $expected): void
    {
        $e = ErrorMapper::map($errCode, 'msg', $status, Uri::parse('https://api.example/x'), 'connect');
        self::assertInstanceOf($expected, $e);
        self::assertStringContainsString('api.example', $e->getMessage());
    }
}
