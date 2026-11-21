<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use DateTimeInterface;
use Omnitrix\Contracts\Filesystem as Disk;
use Omnitrix\Core\IO\Filesystem;
use Omnitrix\Core\IO\IoException;
use Omnitrix\Http\Response;
use Omnitrix\Http\UploadedFile;
use Omnitrix\Routing\UrlGenerator;

use function array_map;
use function chmod;
use function clearstatcache;
use function dirname;
use function explode;
use function filemtime;
use function fileperms;
use function filesize;
use function implode;
use function is_dir;
use function is_file;
use function is_int;
use function rawurlencode;
use function rename;
use function rtrim;
use function scandir;
use function sort;

/**
 * A directory on the server (`storage/app`). Files are written without blocking the worker;
 * `public` files are readable by others (0644), `private` ones by the owner only (0600).
 * `temporaryUrl()` needs {@see StorageRoutes::register()}.
 */
final class LocalDisk implements Disk
{
    private readonly string $root;

    private readonly Filesystem $io;

    public function __construct(
        private readonly string $name,
        string $root,
        private readonly ?string $url = null,
        private readonly string $visibility = 'private',
        private readonly ?UrlGenerator $urls = null,
    ) {
        $this->root = rtrim($root, '/');
        $this->io = new Filesystem();
        Path::visibility($visibility);
    }

    public function name(): string
    {
        return $this->name;
    }

    /** The absolute path of a file on this disk. */
    public function path(string $path): string
    {
        return $this->root . '/' . Path::normalize($path);
    }

    public function exists(string $path): bool
    {
        return is_file($this->path($path));
    }

    public function get(string $path): string
    {
        $file = $this->existing($path);

        return $this->io->read($file);
    }

    public function chunks(string $path): iterable
    {
        return $this->io->readChunks($this->existing($path));
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $file = $this->path($path);
        $this->io->ensureDirectory(dirname($file), 0o755);
        $this->io->write($file, $contents);
        $this->permissions($file, $options['visibility'] ?? null);
    }

    public function putFileAs(string $path, string|UploadedFile $file, array $options = []): void
    {
        $source = $file instanceof UploadedFile ? $file->path() : $file;
        if (!is_file($source)) {
            throw new FilesystemException("No such file to store: {$source}");
        }
        $target = $this->path($path);
        $this->io->writeChunks($target, $this->io->readChunks($source));
        $this->permissions($target, $options['visibility'] ?? null);
    }

    public function delete(string|array $paths): bool
    {
        $ok = true;
        foreach ((array) $paths as $path) {
            $file = $this->path($path);
            if (is_file($file) && !$this->io->delete($file)) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function copy(string $from, string $to): void
    {
        $source = $this->existing($from);
        $target = $this->path($to);
        $this->io->writeChunks($target, $this->io->readChunks($source));
        @chmod($target, fileperms($source) & 0o777);
    }

    public function move(string $from, string $to): void
    {
        $source = $this->existing($from);
        $target = $this->path($to);
        $this->io->ensureDirectory(dirname($target), 0o755);
        if (!@rename($source, $target)) {
            throw new FilesystemException("Could not move {$from} to {$to}");
        }
    }

    public function size(string $path): int
    {
        $size = @filesize($this->existing($path));

        return is_int($size) ? $size : 0;
    }

    public function lastModified(string $path): int
    {
        $file = $this->existing($path);
        clearstatcache(true, $file);
        $time = @filemtime($file);

        return is_int($time) ? $time : 0;
    }

    public function mimeType(string $path): ?string
    {
        return MimeTypes::detect($this->existing($path));
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = Path::normalize($directory, allowEmpty: true);
        $files = [];
        $this->collect($directory, $recursive, $files);
        sort($files);

        return $files;
    }

    public function deleteDirectory(string $directory): void
    {
        $dir = $this->root . '/' . Path::normalize($directory);
        if (is_dir($dir)) {
            try {
                $this->io->deleteDirectory($dir);
            } catch (IoException $e) {
                throw new FilesystemException($e->getMessage(), 0, $e);
            }
        }
    }

    public function url(string $path): string
    {
        if ($this->url === null) {
            throw new FilesystemException("Disk '{$this->name}' has no public url");
        }

        return rtrim($this->url, '/') . '/' . self::encode(Path::normalize($path));
    }

    public function temporaryUrl(string $path, int|DateTimeInterface $expiration): string
    {
        if ($this->urls === null) {
            throw new FilesystemException('Temporary URLs need the URL generator (app.url and app.key)');
        }

        return $this->urls->temporarySignedRoute(StorageRoutes::TEMPORARY, $expiration, ['disk' => $this->name, 'path' => Path::normalize($path)]);
    }

    public function response(string $path, ?string $downloadName = null): Response
    {
        $file = $this->existing($path);

        return ServesFiles::headers(Response::file($file), MimeTypes::forPath($file), $file, $downloadName);
    }

    private function existing(string $path): string
    {
        $file = $this->path($path);
        if (!is_file($file)) {
            throw FileNotFoundException::at($this->name, $path);
        }

        return $file;
    }

    private function permissions(string $file, ?string $visibility): void
    {
        @chmod($file, Path::visibility($visibility ?? $this->visibility) === 'public' ? 0o644 : 0o600);
    }

    /** @param list<string> $files */
    private function collect(string $directory, bool $recursive, array &$files): void
    {
        $dir = $directory === '' ? $this->root : $this->root . '/' . $directory;
        $entries = is_dir($dir) ? scandir($dir) : false;
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $directory === '' ? $entry : $directory . '/' . $entry;
            if (is_dir($dir . '/' . $entry)) {
                if ($recursive) {
                    $this->collect($relative, true, $files);
                }
            } else {
                $files[] = $relative;
            }
        }
    }

    /** Encodes each path segment for a URL. */
    public static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }
}
