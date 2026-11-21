<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Filesystem;

use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Filesystem\StorageRoutes;
use Omnitrix\Http\UploadedFile;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\TestCase;
use Omnitrix\Tests\Unit\Filesystem\StorageTest;
use PHPUnit\Framework\Attributes\CoversClass;

use function array_slice;
use function bin2hex;
use function dirname;
use function explode;
use function fclose;
use function feof;
use function fileperms;
use function fread;
use function fwrite;
use function hexdec;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function random_bytes;
use function str_repeat;
use function str_replace;
use function stream_socket_client;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;
use function uniqid;

/** Multipart uploads through a real server, validated, stored and served back. */
#[CoversClass(UploadedFile::class)]
#[CoversClass(StorageRoutes::class)]
final class UploadsTest extends TestCase
{
    private const int PORT = 18720;

    private static ?ProcessRunner $server = null;

    private static string $root = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$root = sys_get_temp_dir() . '/omnitrix-uploads-' . uniqid();
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/storage_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'FIXTURE_ROOT' => self::$root, 'FIXTURE_KEY' => AesEncrypter::generateKey(),
        ]);
        self::$server->waitForPort('127.0.0.1', self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(15.0);
        self::$server = null;
        parent::tearDownAfterClass();
    }

    public function testImageUploadIsValidatedStoredAndServed(): void
    {
        $png = StorageTest::png();
        [$status, , $body] = $this->post('/avatars', ['name' => 'Ann'], [['avatar', '../../evil.png', 'image/png', $png]]);
        self::assertSame(201, $status, $body);
        $json = self::json($body);
        self::assertSame('Ann', $json['name']);
        self::assertSame('../../evil.png', $json['client'], 'the client name is kept but not used for the path');
        $path = $json['path'];
        self::assertIsString($path);
        self::assertMatchesRegularExpression('#^avatars/[0-9a-f]{40}\.png$#', $path);
        self::assertTrue(is_file(self::$root . '/public/' . $path));
        self::assertSame(0o644, fileperms(self::$root . '/public/' . $path) & 0o777, 'public disk');
        self::assertSame('http://127.0.0.1:' . self::PORT . '/storage/' . $path, $json['url']);

        [$status, $headers, $served] = $this->get('/storage/' . $path);
        self::assertSame(200, $status);
        self::assertSame($png, $served);
        self::assertSame('image/png', $headers['content-type']);
        self::assertStringStartsWith('inline', $headers['content-disposition']);
        self::assertSame('nosniff', $headers['x-content-type-options']);
        self::assertSame(404, $this->get('/storage/avatars/missing.png')[0]);
        self::assertSame(404, $this->get('/storage/..%2F..%2Fetc%2Fpasswd')[0], 'no path leaves the disk');

        [$status, , $body] = $this->post('/avatars', ['name' => 'Ann'], [['avatar', 'photo.png', 'image/png', 'this is not an image']]);
        self::assertSame(422, $status);
        self::assertStringContainsString('must be an image', $body, 'the type comes from the contents');
        [$status, , $body] = $this->post('/avatars', ['name' => 'Ann'], [['avatar', 'big.png', 'image/png', $png . str_repeat("\0", 600 * 1024)]]);
        self::assertSame(422, $status);
        self::assertStringContainsString('may not be greater than 512', $body);
        self::assertSame(422, $this->post('/avatars', ['name' => 'Ann'], [])[0], 'the file is required');
    }

    public function testPrivateFilesNeedATemporaryLink(): void
    {
        [$status, , $body] = $this->post('/documents', [], [['doc', 'notes.txt', 'text/plain', "line one\nline two\n"], ['photos[]', 'a.png', 'image/png', StorageTest::png()], ['photos[]', 'b.png', 'image/png', StorageTest::png()]]);
        self::assertSame(201, $status, $body);
        $json = self::json($body);
        self::assertSame('docs/report.txt', $json['path']);
        self::assertSame(['a.png', 'b.png'], $json['photos'], 'several files under one name');
        self::assertSame(0o600, fileperms(self::$root . '/private/docs/report.txt') & 0o777, 'private disk');

        self::assertIsString($json['link']);
        self::assertIsString($json['expired']);
        $link = substr($json['link'], strlen('http://127.0.0.1:' . self::PORT));
        self::assertStringStartsWith('/storage-temporary/local/docs/report.txt?', $link, 'real slashes in the link');
        [$status, $headers, $served] = $this->get($link);
        self::assertSame(200, $status);
        self::assertSame("line one\nline two\n", $served);
        self::assertStringStartsWith('text/plain', $headers['content-type']);
        self::assertSame(403, $this->get(substr($json['expired'], strlen('http://127.0.0.1:' . self::PORT)))[0], 'expired link');
        self::assertSame(403, $this->get(str_replace('docs/report.txt', 'docs/other.txt', $link))[0], 'a link is only valid for its file');
        self::assertSame(403, $this->get('/storage-temporary/local/docs/report.txt')[0], 'no signature');
        self::assertSame(404, $this->get('/storage/docs/report.txt')[0], 'not on the public disk');
    }

    /** @return array<string, string|list<string>> */
    private static function json(string $body): array
    {
        $json = json_decode($body, true);
        self::assertIsArray($json);
        $out = [];
        foreach ($json as $key => $value) {
            self::assertTrue(is_string($value) || is_array($value));
            $out[(string) $key] = $value;
        }

        /** @var array<string, string|list<string>> $out */
        return $out;
    }

    /**
     * @param array<string, string>                        $fields
     * @param list<array{string, string, string, string}> $files field, file name, type, contents
     *
     * @return array{int, array<string, string>, string}
     */
    private function post(string $target, array $fields, array $files): array
    {
        $boundary = '----omnitrix' . bin2hex(random_bytes(8));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as [$name, $filename, $type, $contents]) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\nContent-Type: {$type}\r\n\r\n{$contents}\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->send("POST {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    }

    /** @return array{int, array<string, string>, string} */
    private function get(string $target): array
    {
        return $this->send("GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
    }

    /** @return array{int, array<string, string>, string} */
    private function send(string $request): array
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $error, 5.0);
        self::assertTrue(is_resource($socket), (string) $error);
        fwrite($socket, $request);
        $raw = '';
        while (!feof($socket)) {
            $raw .= (string) fread($socket, 65536);
        }
        fclose($socket);
        $end = strpos($raw, "\r\n\r\n");
        self::assertNotFalse($end, $raw);
        $lines = explode("\r\n", substr($raw, 0, $end));
        $status = (int) substr($lines[0], 9, 3);
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }
        $body = substr($raw, $end + 4);
        if (($headers['transfer-encoding'] ?? '') === 'chunked') {
            $decoded = '';
            while ($body !== '' && ($eol = strpos($body, "\r\n")) !== false) {
                $size = hexdec(substr($body, 0, $eol));
                if ($size === 0) {
                    break;
                }
                $decoded .= substr($body, $eol + 2, (int) $size);
                $body = substr($body, $eol + 2 + (int) $size + 2);
            }
            $body = $decoded;
        }

        return [$status, $headers, $body];
    }
}
