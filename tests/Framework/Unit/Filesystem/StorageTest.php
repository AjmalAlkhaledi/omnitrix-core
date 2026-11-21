<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Unit\Filesystem;

use InvalidArgumentException;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Filesystem\FileNotFoundException;
use Omnitrix\Filesystem\FilesystemException;
use Omnitrix\Filesystem\FilesystemManager;
use Omnitrix\Filesystem\LocalDisk;
use Omnitrix\Filesystem\MemoryDisk;
use Omnitrix\Filesystem\Path;
use Omnitrix\Filesystem\S3\S3Disk;
use Omnitrix\Http\UploadedFile;
use Omnitrix\Tests\Support\FakeS3Transport;
use Omnitrix\Tests\Support\TestCase;
use Omnitrix\Validation\ValidationException;
use Omnitrix\Validation\Validator;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_keys;
use function array_map;
use function base64_decode;
use function fileperms;
use function implode;
use function iterator_to_array;
use function str_repeat;
use function strtotime;
use function sys_get_temp_dir;
use function uniqid;

use const UPLOAD_ERR_INI_SIZE;

#[CoversClass(LocalDisk::class)]
#[CoversClass(MemoryDisk::class)]
#[CoversClass(S3Disk::class)]
#[CoversClass(Path::class)]
#[CoversClass(UploadedFile::class)]
#[CoversClass(FilesystemManager::class)]
final class StorageTest extends TestCase
{
    /** A real 1×1 PNG. */
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }

    public function testPathsCannotLeaveTheRoot(): void
    {
        self::assertSame('a/b/c.txt', Path::normalize('/a//b/./c.txt/'));
        foreach (['../etc/passwd', 'a/../../b', "a\0b", 'a\\b', "a\nb", ''] as $bad) {
            try {
                Path::normalize($bad);
                self::fail("accepted {$bad}");
            } catch (InvalidArgumentException) {
                self::assertNotSame('x', $bad);
            }
        }
        self::assertSame('avatars/x.png', Path::join('avatars/', 'x.png'));
    }

    public function testLocalDisk(): void
    {
        $root = sys_get_temp_dir() . '/omnitrix-disk-' . uniqid();
        $disk = new LocalDisk('local', $root, 'https://cdn.example.com/files');
        $disk->put('docs/a.txt', 'hello');
        $disk->put('docs/deep/b.txt', 'world', ['visibility' => 'public']);
        self::assertTrue($disk->exists('docs/a.txt'));
        self::assertSame('hello', $disk->get('docs/a.txt'));
        self::assertSame(0o600, fileperms($disk->path('docs/a.txt')) & 0o777, 'private by default');
        self::assertSame(0o644, fileperms($disk->path('docs/deep/b.txt')) & 0o777);
        self::assertSame(['docs/a.txt'], $disk->files('docs'));
        self::assertSame(['docs/a.txt', 'docs/deep/b.txt'], $disk->files('', recursive: true));
        $disk->copy('docs/a.txt', 'copy/a.txt');
        $disk->move('copy/a.txt', 'moved/a.txt');
        self::assertFalse($disk->exists('copy/a.txt'));
        self::assertSame('hello', implode('', iterator_to_array($disk->chunks('moved/a.txt'), false)));
        self::assertSame(5, $disk->size('moved/a.txt'));
        self::assertGreaterThan(0, $disk->lastModified('moved/a.txt'));
        self::assertSame('text/plain', $disk->mimeType('moved/a.txt'));
        self::assertSame('https://cdn.example.com/files/my%20file/%C3%A9.txt', $disk->url('my file/é.txt'));
        $response = $disk->response('docs/a.txt', 'Report é.txt');
        self::assertStringStartsWith('attachment; filename="Report _.txt"; filename*=UTF-8\'\'Report%20%C3%A9.txt', (string) $response->getHeader('Content-Disposition'));
        $disk->put('page.html', '<script>alert(1)</script>');
        self::assertStringStartsWith('attachment', (string) $disk->response('page.html')->getHeader('Content-Disposition'), 'HTML is never shown inline');
        self::assertStringContainsString('sandbox', (string) $disk->response('docs/a.txt')->getHeader('Content-Security-Policy'));
        self::assertTrue($disk->delete(['docs/a.txt', 'missing.txt']));
        $disk->deleteDirectory('docs');
        self::assertSame(['moved/a.txt', 'page.html'], $disk->files('', recursive: true));
        $this->expectException(FileNotFoundException::class);
        $disk->get('docs/a.txt');
    }

    public function testLocalDiskRejectsTraversal(): void
    {
        $disk = new LocalDisk('local', sys_get_temp_dir() . '/omnitrix-disk-' . uniqid());
        $this->expectException(InvalidArgumentException::class);
        $disk->put('../escape.txt', 'x');
    }

    public function testUploadsAreNormalizedAndDetectedFromContents(): void
    {
        $png = UploadedFile::fake('ignored.txt', self::png(), 'text/plain');
        $text = UploadedFile::fake('photo.png', 'not an image', 'image/png');
        $files = UploadedFile::normalize([
            'avatar' => ['name' => 'me.txt', 'type' => 'text/plain', 'tmp_name' => $png->path(), 'error' => 0, 'size' => 70],
            'photos' => [['name' => 'a.png', 'type' => 'image/png', 'tmp_name' => $png->path(), 'error' => 0, 'size' => 70], ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => $text->path(), 'error' => 0, 'size' => 12]],
            'php_layout' => ['name' => ['x.png', 'y.png'], 'type' => ['image/png', 'image/png'], 'tmp_name' => [$png->path(), ''], 'error' => [0, 4], 'size' => [70, 0]],
            'empty' => ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => 4, 'size' => 0],
        ]);
        self::assertSame(['avatar', 'photos', 'php_layout'], array_keys($files), 'empty file inputs are left out');
        $avatar = $files['avatar'];
        self::assertInstanceOf(UploadedFile::class, $avatar);
        self::assertSame('image/png', $avatar->mimeType(), 'type from the contents, not the client');
        self::assertSame('png', $avatar->extension());
        self::assertSame('text/plain', $avatar->clientMimeType());
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}\.png$/', $avatar->hashName());
        self::assertIsArray($files['photos']);
        self::assertCount(2, $files['photos']);
        self::assertIsArray($files['php_layout']);
        self::assertCount(1, $files['php_layout']);
        self::assertSame('txt', $text->extension(), 'a text file named .png is still text');
    }

    public function testFileValidationRules(): void
    {
        $validator = new Validator(['avatar' => 'required|file|image|max:1', 'doc' => 'file|mimes:pdf,txt', 'any' => 'file|mimetypes:image/*']);
        $png = UploadedFile::fake('me.png', self::png());
        $valid = $validator->validate(['avatar' => $png, 'doc' => UploadedFile::fake('a.txt', 'plain text'), 'any' => $png]);
        self::assertSame($png, $valid['avatar']);

        $cases = [
            'not an image' => ['avatar' => UploadedFile::fake('fake.png', 'plain text')],
            'svg is not an image' => ['avatar' => UploadedFile::fake('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'too large (max in KB)' => ['avatar' => UploadedFile::fake('big.png', self::png() . str_repeat("\0", 2048))],
            'failed upload' => ['avatar' => new UploadedFile('', 'x.png', 'image/png', UPLOAD_ERR_INI_SIZE)],
            'missing' => [],
            'a string is not a file' => ['avatar' => 'me.png'],
            'wrong type' => ['avatar' => $png, 'doc' => $png],
        ];
        foreach ($cases as $label => $input) {
            try {
                $validator->validate($input);
                self::fail("accepted: {$label}");
            } catch (ValidationException $e) {
                self::assertNotSame([], $e->errors, $label);
            }
        }
    }

    public function testMemoryDiskAndManager(): void
    {
        $manager = new FilesystemManager(['default' => 'local', 'disks' => ['local' => ['driver' => 'local', 'root' => sys_get_temp_dir()], 'public' => ['driver' => 'local', 'root' => sys_get_temp_dir(), 'url' => '/storage']]], appUrl: 'https://api.example.com');
        self::assertInstanceOf(LocalDisk::class, $manager->disk());
        self::assertSame('https://api.example.com/storage/a.png', $manager->disk('public')->url('a.png'), 'relative urls use app.url');
        $fake = $manager->fake('public');
        self::assertSame($fake, $manager->disk('public'));
        $fake->put('avatars/a.png', self::png());
        $fake->assertExists('avatars/a.png');
        $fake->assertMissing('avatars/b.png');
        $fake->assertCount(1);
        self::assertSame('image/png', $fake->mimeType('avatars/a.png'));
        self::assertSame('https://api.example.com/storage/avatars/a.png', $fake->url('avatars/a.png'));
        $this->expectException(InvalidArgumentException::class);
        $manager->disk('nope');
    }

    public function testS3Disk(): void
    {
        $transport = new FakeS3Transport('media', 'AKID', 'SECRET', 'eu-central-1', pathStyle: true, pageSize: 2);
        $disk = new S3Disk('s3', ['key' => 'AKID', 'secret' => 'SECRET', 'region' => 'eu-central-1', 'bucket' => 'media', 'endpoint' => 'http://s3.test:9000', 'root' => 'app', 'multipart_threshold' => 6 * 1024 * 1024, 'part_size' => 5 * 1024 * 1024], new HttpClient(HttpClientConfig::defaults(), $transport));

        $disk->put('docs/hello world+é.txt', 'hello');
        self::assertArrayHasKey('app/docs/hello world+é.txt', $transport->objects, 'stored under the root prefix with the exact key');
        self::assertSame('hello', $disk->get('docs/hello world+é.txt'));
        self::assertSame('text/plain', $transport->objects['app/docs/hello world+é.txt']['type']);
        self::assertTrue($disk->exists('docs/hello world+é.txt'));
        self::assertFalse($disk->exists('docs/nope.txt'));
        self::assertSame(5, $disk->size('docs/hello world+é.txt'));
        self::assertSame('text/plain', $disk->mimeType('docs/hello world+é.txt'));
        self::assertSame(strtotime('Fri, 15 Jan 2027 08:00:00 GMT'), $disk->lastModified('docs/hello world+é.txt'));
        self::assertSame('hello', implode('', iterator_to_array($disk->chunks('docs/hello world+é.txt'), false)), 'streamed through a temporary file');

        $disk->put('docs/b.txt', 'b');
        $disk->put('docs/c.txt', 'c');
        $disk->put('docs/sub/d.txt', 'd');
        self::assertSame(['docs/b.txt', 'docs/c.txt', 'docs/hello world+é.txt'], $disk->files('docs'), 'pages of 2 are followed');
        self::assertSame(['docs/b.txt', 'docs/c.txt', 'docs/hello world+é.txt', 'docs/sub/d.txt'], $disk->files('docs', recursive: true));

        $disk->copy('docs/b.txt', 'copies/b.txt');
        $disk->move('docs/c.txt', 'copies/c.txt');
        self::assertSame(['copies/b.txt', 'copies/c.txt'], $disk->files('copies'));
        $disk->deleteDirectory('docs');
        self::assertSame(['copies/b.txt', 'copies/c.txt'], $disk->files('', recursive: true));

        $big = str_repeat('0123456789', 1_300_000); // 13 MB → three parts of 5, 5 and 3 MB
        $file = UploadedFile::fake('video.bin', $big);
        $disk->putFileAs('uploads/video.bin', $file);
        self::assertSame(3, $transport->parts, 'uploaded in parts');
        self::assertSame($big, $transport->objects['app/uploads/video.bin']['body']);

        $url = $disk->temporaryUrl('copies/b.txt', 600);
        self::assertStringStartsWith('http://s3.test:9000/media/app/copies/b.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&', $url);
        self::assertStringContainsString('X-Amz-Expires=600', $url);
        self::assertSame('http://s3.test:9000/media/app/copies/b.txt', $disk->url('copies/b.txt'));
        $redirect = $disk->response('copies/b.txt', 'b.txt');
        self::assertSame(302, $redirect->getStatus());
        self::assertStringContainsString('response-content-disposition=attachment', (string) $redirect->getHeader('Location'));

        self::assertNotContains('403', array_map(static fn(string $l): string => $l, $transport->log));
        $this->expectException(FileNotFoundException::class);
        $disk->get('missing.txt');
    }

    public function testS3ErrorsAreReported(): void
    {
        $transport = new FakeS3Transport('media', 'AKID', 'SECRET', 'eu-central-1');
        $wrongSecret = new S3Disk('s3', ['key' => 'AKID', 'secret' => 'WRONG', 'region' => 'eu-central-1', 'bucket' => 'media', 'endpoint' => 'http://s3.test:9000'], new HttpClient(HttpClientConfig::defaults(), $transport));
        try {
            $wrongSecret->put('a.txt', 'x');
            self::fail('accepted a bad signature');
        } catch (FilesystemException $e) {
            self::assertStringContainsString('HTTP 403 SignatureDoesNotMatch', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        new S3Disk('s3', ['key' => 'AKID', 'region' => 'eu-central-1', 'bucket' => 'media'], new HttpClient(HttpClientConfig::defaults(), $transport));
    }
}
