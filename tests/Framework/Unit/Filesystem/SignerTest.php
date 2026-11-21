<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Filesystem;

use DateTimeImmutable;
use DateTimeZone;
use Omnitrix\Filesystem\S3\Signer;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function hash;
use function preg_match;

/** The worked examples of the AWS S3 documentation ("Signature Calculations … Authorization header" and "query parameters"). */
#[CoversClass(Signer::class)]
final class SignerTest extends TestCase
{
    private static function signer(): Signer
    {
        return new Signer('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1');
    }

    private static function when(): DateTimeImmutable
    {
        return new DateTimeImmutable('2013-05-24 00:00:00', new DateTimeZone('UTC'));
    }

    /** @param array<string, string> $headers */
    private static function signature(array $headers): string
    {
        preg_match('/Signature=([0-9a-f]{64})/', $headers['Authorization'], $m);

        return $m[1] ?? '';
    }

    public function testGetObject(): void
    {
        $h = self::signer()->sign('GET', '/test.txt', [], ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9'], hash('sha256', ''), self::when());
        self::assertSame('f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', self::signature($h));
        self::assertStringContainsString('SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,', $h['Authorization']);
        self::assertSame('20130524T000000Z', $h['x-amz-date']);
    }

    public function testPutObject(): void
    {
        $body = 'Welcome to Amazon S3.';
        $h = self::signer()->sign('PUT', Signer::encodePath('/test$file.text'), [], ['host' => 'examplebucket.s3.amazonaws.com', 'date' => 'Fri, 24 May 2013 00:00:00 GMT', 'x-amz-storage-class' => 'REDUCED_REDUNDANCY'], hash('sha256', $body), self::when());
        self::assertSame('98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd', self::signature($h));
    }

    public function testBucketQueries(): void
    {
        $empty = hash('sha256', '');
        $lifecycle = self::signer()->sign('GET', '/', ['lifecycle' => ''], ['host' => 'examplebucket.s3.amazonaws.com'], $empty, self::when());
        self::assertSame('fea454ca298b7da1c68078a5d1bdbfbbe0d65c699e0f91ac7a200a0136783543', self::signature($lifecycle));
        $list = self::signer()->sign('GET', '/', ['max-keys' => '2', 'prefix' => 'J'], ['host' => 'examplebucket.s3.amazonaws.com'], $empty, self::when());
        self::assertSame('34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', self::signature($list));
    }

    public function testPresignedUrl(): void
    {
        $query = self::signer()->presign('GET', '/test.txt', 'examplebucket.s3.amazonaws.com', 86400, [], self::when());
        self::assertStringEndsWith('&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $query);
        self::assertStringContainsString('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $query);
    }

    public function testEncoding(): void
    {
        self::assertSame('/a%20b/%C3%A9t%C3%A9/c%2Bd.txt', Signer::encodePath('/a b/été/c+d.txt'));
        self::assertSame('a=1&b=x%20y&list-type=2', Signer::query(['list-type' => '2', 'b' => 'x y', 'a' => '1']));
    }
}
