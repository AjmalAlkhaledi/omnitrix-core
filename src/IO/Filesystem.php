<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use Generator;
use OpenSwoole\Coroutine\System;

use function basename;
use function dirname;
use function fclose;
use function feof;
use function file_exists;
use function fopen;
use function fread;
use function fwrite;
use function is_dir;
use function is_file;
use function is_string;
use function max;
use function mkdir;
use function rename;
use function rmdir;
use function scandir;
use function strlen;
use function uniqid;
use function unlink;

use const FILE_APPEND;

/**
 * Filesystem helpers that do not block the event loop.
 *
 * Whole-file reads/writes use `Coroutine\System::readFile/writeFile` (AIO thread pool; the
 * io_uring file engine where the kernel supports it). Streaming uses `fopen`/`fread`, which
 * the HOOK_FILE runtime hook dispatches to the same pool. Metadata calls (`file_exists`,
 * `is_file`, `mkdir`, `rename`, `unlink`) are fast syscalls executed inline; on network
 * filesystems they can stall the worker and should be avoided in request paths.
 */
final class Filesystem
{
    /** @throws IoException */
    public function read(string $path): string
    {
        if (!is_file($path)) {
            throw new IoException("File not found: {$path}");
        }
        $data = System::readFile($path);
        if (!is_string($data)) {
            throw new IoException("Could not read {$path}");
        }

        return $data;
    }

    /** Atomic write: data goes to a sibling temp file which is then renamed over the target. */
    public function write(string $path, string $data): void
    {
        $this->ensureDirectory(dirname($path));
        $tmp = dirname($path) . '/.' . basename($path) . '.' . uniqid('', true) . '.tmp';
        if (System::writeFile($tmp, $data) !== strlen($data)) {
            @unlink($tmp);
            throw new IoException("Could not write {$path}");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new IoException("Could not move temporary file over {$path}");
        }
    }

    public function append(string $path, string $data): void
    {
        $this->ensureDirectory(dirname($path));
        if (System::writeFile($path, $data, FILE_APPEND) !== strlen($data)) {
            throw new IoException("Could not append to {$path}");
        }
    }

    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    public function delete(string $path): bool
    {
        return is_file($path) && unlink($path);
    }

    public function ensureDirectory(string $path, int $mode = 0o775): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new IoException("Could not create directory {$path}");
        }
    }

    /** Removes a directory tree. */
    public function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->deleteDirectory($full) : @unlink($full);
        }
        @rmdir($path);
    }

    /**
     * Streams a file in chunks without loading it into memory.
     *
     * @return Generator<int, string>
     */
    public function readChunks(string $path, int $chunkSize = 65536): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new IoException("Could not open {$path}");
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, max(1, $chunkSize));
                if ($chunk === false) {
                    throw new IoException("Read error on {$path}");
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Writes chunks from any iterable to `$path` (streaming copy); returns bytes written.
     *
     * @param iterable<string> $chunks
     */
    public function writeChunks(string $path, iterable $chunks): int
    {
        $this->ensureDirectory(dirname($path));
        $handle = @fopen($path, 'wb');
        if ($handle === false) {
            throw new IoException("Could not open {$path} for writing");
        }
        $total = 0;
        try {
            foreach ($chunks as $chunk) {
                $written = fwrite($handle, $chunk);
                if ($written === false || $written !== strlen($chunk)) {
                    throw new IoException("Short write on {$path}");
                }
                $total += $written;
            }
        } finally {
            fclose($handle);
        }

        return $total;
    }
}
