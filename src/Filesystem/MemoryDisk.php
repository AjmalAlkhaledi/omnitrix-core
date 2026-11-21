<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use DateTimeInterface;
use Omnitrix\Contracts\Filesystem as Disk;
use Omnitrix\Core\IO\Filesystem;
use Omnitrix\Http\Response;
use Omnitrix\Http\UploadedFile;
use PHPUnit\Framework\Assert;

use function array_keys;
use function count;
use function implode;
use function is_file;
use function rawurlencode;
use function rtrim;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function substr;
use function time;

/** A disk in memory, for tests (`Storage::fake('public')`), with assertions. */
final class MemoryDisk implements Disk
{
    /** @var array<string, array{contents: string, type: ?string, modified: int, visibility: string}> */
    private array $files = [];

    public function __construct(private readonly string $name = 'memory', private readonly string $url = 'http://localhost/storage') {}

    public function exists(string $path): bool
    {
        return isset($this->files[Path::normalize($path)]);
    }

    public function get(string $path): string
    {
        return $this->file($path)['contents'];
    }

    public function chunks(string $path): iterable
    {
        $contents = $this->get($path);
        for ($offset = 0; $offset < strlen($contents); $offset += 65536) {
            yield substr($contents, $offset, 65536);
        }
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $this->files[Path::normalize($path)] = ['contents' => $contents, 'type' => $options['content_type'] ?? MimeTypes::detectBuffer($contents), 'modified' => time(), 'visibility' => Path::visibility($options['visibility'] ?? 'private')];
    }

    public function putFileAs(string $path, string|UploadedFile $file, array $options = []): void
    {
        $source = $file instanceof UploadedFile ? $file->path() : $file;
        if (!is_file($source)) {
            throw new FilesystemException("No such file to store: {$source}");
        }
        $this->put($path, (new Filesystem())->read($source), $options + ['content_type' => MimeTypes::detect($source) ?? 'application/octet-stream']);
    }

    public function delete(string|array $paths): bool
    {
        foreach ((array) $paths as $path) {
            unset($this->files[Path::normalize($path)]);
        }

        return true;
    }

    public function copy(string $from, string $to): void
    {
        $this->files[Path::normalize($to)] = $this->file($from);
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function size(string $path): int
    {
        return strlen($this->get($path));
    }

    public function lastModified(string $path): int
    {
        return $this->file($path)['modified'];
    }

    public function mimeType(string $path): ?string
    {
        return $this->file($path)['type'];
    }

    public function visibility(string $path): string
    {
        return $this->file($path)['visibility'];
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = Path::normalize($directory, allowEmpty: true);
        $prefix = $directory === '' ? '' : $directory . '/';
        $out = [];
        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, $prefix) && ($recursive || !str_contains(substr($path, strlen($prefix)), '/'))) {
                $out[] = $path;
            }
        }
        sort($out);

        return $out;
    }

    public function deleteDirectory(string $directory): void
    {
        $this->delete($this->files($directory, recursive: true));
    }

    public function url(string $path): string
    {
        return rtrim($this->url, '/') . '/' . LocalDisk::encode(Path::normalize($path));
    }

    public function temporaryUrl(string $path, int|DateTimeInterface $expiration): string
    {
        $until = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : time() + $expiration;

        return $this->url($path) . '?expires=' . $until . '&disk=' . rawurlencode($this->name);
    }

    public function response(string $path, ?string $downloadName = null): Response
    {
        $file = $this->file($path);

        return ServesFiles::headers(Response::text($file['contents']), $file['type'] ?? MimeTypes::forPath($path), $path, $downloadName);
    }

    public function assertExists(string $path, ?string $contents = null): void
    {
        Assert::assertTrue($this->exists($path), "{$path} is not on disk '{$this->name}' (has: " . implode(', ', array_keys($this->files)) . ')');
        if ($contents !== null) {
            Assert::assertSame($contents, $this->get($path));
        }
    }

    public function assertMissing(string $path): void
    {
        Assert::assertFalse($this->exists($path), "{$path} is on disk '{$this->name}'");
    }

    public function assertCount(int $count, string $directory = ''): void
    {
        Assert::assertSame($count, count($this->files($directory, recursive: true)));
    }

    /** @return array{contents: string, type: ?string, modified: int, visibility: string} */
    private function file(string $path): array
    {
        $key = Path::normalize($path);
        if (!isset($this->files[$key])) {
            throw FileNotFoundException::at($this->name, $path);
        }

        return $this->files[$key];
    }
}
