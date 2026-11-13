<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use Omnitrix\Core\Runtime\Context\RequestContext;

use function bin2hex;
use function count;
use function is_file;
use function random_bytes;
use function rtrim;
use function sys_get_temp_dir;
use function touch;
use function unlink;

/**
 * Temporary files with guaranteed cleanup. Files are created with unpredictable names
 * (128 random bits) and tracked; {@see cleanup()} removes all of them. Files created
 * through {@see forRequest()} are removed when the request finishes, even if it failed.
 * Register the worker instance's cleanup with the ResourceRegistry for worker-lifetime files.
 */
final class TempFiles
{
    /** @var array<string, true> */
    private array $files = [];

    private readonly string $directory;

    public function __construct(?string $directory = null, private readonly Filesystem $filesystem = new Filesystem())
    {
        $this->directory = rtrim($directory ?? sys_get_temp_dir(), '/');
        $this->filesystem->ensureDirectory($this->directory);
    }

    public function create(string $prefix = 'omnitrix-', string $suffix = '.tmp'): string
    {
        $path = $this->directory . '/' . $prefix . bin2hex(random_bytes(16)) . $suffix;
        if (!touch($path)) {
            throw new IoException("Could not create temporary file in {$this->directory}");
        }
        $this->files[$path] = true;

        return $path;
    }

    /** Creates a temp file removed automatically when `$context` is disposed. */
    public function forRequest(RequestContext $context, string $prefix = 'omnitrix-', string $suffix = '.tmp'): string
    {
        $path = $this->create($prefix, $suffix);
        $context->defer(function () use ($path): void {
            $this->remove($path);
        });

        return $path;
    }

    public function remove(string $path): void
    {
        unset($this->files[$path]);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return int number of files removed */
    public function cleanup(): int
    {
        $removed = 0;
        foreach ($this->files as $path => $_) {
            if (is_file($path) && @unlink($path)) {
                $removed++;
            }
        }
        $this->files = [];

        return $removed;
    }

    public function count(): int
    {
        return count($this->files);
    }
}
