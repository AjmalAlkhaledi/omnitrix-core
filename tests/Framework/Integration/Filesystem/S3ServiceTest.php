<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Filesystem;

use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Filesystem\FileNotFoundException;
use Omnitrix\Filesystem\S3\S3Disk;
use Omnitrix\Http\UploadedFile;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function getenv;
use function implode;
use function is_string;
use function iterator_to_array;
use function str_repeat;
use function uniqid;

/**
 * The S3 driver against a real S3-compatible service (AWS, R2, Garage, …). Runs when
 * OMNITRIX_TEST_S3_ENDPOINT, _KEY, _SECRET, _BUCKET (and optionally _REGION, _PATH_STYLE) are set.
 */
#[CoversClass(S3Disk::class)]
final class S3ServiceTest extends TestCase
{
    public function testRoundTripAgainstARealService(): void
    {
        $env = static function (string $name): ?string {
            $value = getenv('OMNITRIX_TEST_S3_' . $name);

            return is_string($value) && $value !== '' ? $value : null;
        };
        if ($env('ENDPOINT') === null || $env('KEY') === null || $env('SECRET') === null || $env('BUCKET') === null) {
            self::markTestSkipped('No S3 service configured (OMNITRIX_TEST_S3_*)');
        }
        $http = new HttpClient(HttpClientConfig::defaults());
        $disk = new S3Disk('s3', [
            'endpoint' => $env('ENDPOINT'), 'key' => $env('KEY'), 'secret' => $env('SECRET'), 'bucket' => $env('BUCKET'),
            'region' => $env('REGION') ?? 'us-east-1', 'use_path_style_endpoint' => ($env('PATH_STYLE') ?? '1') === '1',
            'root' => 'omnitrix-test-' . uniqid(), 'multipart_threshold' => 6 * 1024 * 1024, 'part_size' => 5 * 1024 * 1024,
        ], $http);
        try {
            $disk->put('a b/é+x.txt', 'hello');
            self::assertSame('hello', $disk->get('a b/é+x.txt'));
            self::assertTrue($disk->exists('a b/é+x.txt'));
            self::assertSame(5, $disk->size('a b/é+x.txt'));
            self::assertSame('text/plain', $disk->mimeType('a b/é+x.txt'));
            $disk->copy('a b/é+x.txt', 'copy.txt');
            self::assertSame(['a b/é+x.txt', 'copy.txt'], $disk->files('', recursive: true));
            self::assertSame(['copy.txt'], $disk->files());
            $big = str_repeat('0123456789', 1_100_000);
            $disk->putFileAs('big.bin', UploadedFile::fake('big.bin', $big));
            self::assertSame(11_000_000, $disk->size('big.bin'), 'multipart upload');
            self::assertSame($big, implode('', iterator_to_array($disk->chunks('big.bin'), false)));
            $link = $disk->temporaryUrl('copy.txt', 60);
            self::assertSame('hello', $http->get($link)->body(), 'presigned URL');
            $disk->deleteDirectory('a b');
            self::assertFalse($disk->exists('a b/é+x.txt'));
            $this->expectException(FileNotFoundException::class);
            $disk->get('a b/é+x.txt');
        } finally {
            $disk->delete($disk->files('', recursive: true));
            $http->close();
        }
    }
}
