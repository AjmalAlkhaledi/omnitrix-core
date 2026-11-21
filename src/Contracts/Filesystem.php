<?php

declare(strict_types=1);

namespace Omnitrix\Contracts;

use DateTimeInterface;
use Omnitrix\Http\Response;
use Omnitrix\Http\UploadedFile;

/**
 * A storage disk (local directory, S3-compatible bucket, memory). Paths are relative to the
 * disk's root and use `/`; `..` and absolute paths are rejected.
 */
interface Filesystem
{
    public function exists(string $path): bool;

    /** @throws \Omnitrix\Filesystem\FileNotFoundException */
    public function get(string $path): string;

    /**
     * The file in chunks, without loading it whole.
     *
     * @return iterable<string>
     */
    public function chunks(string $path): iterable;

    /** @param array{content_type?: string, visibility?: 'public'|'private'} $options */
    public function put(string $path, string $contents, array $options = []): void;

    /**
     * Copies a local file (or an upload) onto the disk, streaming it.
     *
     * @param array{content_type?: string, visibility?: 'public'|'private'} $options
     */
    public function putFileAs(string $path, string|UploadedFile $file, array $options = []): void;

    /** @param string|list<string> $paths true when everything named is gone */
    public function delete(string|array $paths): bool;

    public function copy(string $from, string $to): void;

    public function move(string $from, string $to): void;

    public function size(string $path): int;

    /** Unix timestamp. */
    public function lastModified(string $path): int;

    public function mimeType(string $path): ?string;

    /** @return list<string> file paths under `$directory` */
    public function files(string $directory = '', bool $recursive = false): array;

    public function deleteDirectory(string $directory): void;

    /** Public URL of the file (the disk's `url`). */
    public function url(string $path): string;

    /** A link that works until `$expiration` (seconds from now or a time), for private files. */
    public function temporaryUrl(string $path, int|DateTimeInterface $expiration): string;

    /** An HTTP response with the file: inline, or as a download when `$downloadName` is given. */
    public function response(string $path, ?string $downloadName = null): Response;
}
