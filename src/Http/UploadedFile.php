<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use InvalidArgumentException;
use Omnitrix\Facades\Storage;
use Omnitrix\Filesystem\FilesystemException;
use Omnitrix\Filesystem\MimeTypes;
use Omnitrix\Filesystem\Path;

use function array_is_list;
use function array_key_exists;
use function bin2hex;
use function file_put_contents;
use function filesize;
use function is_array;
use function is_file;
use function is_int;
use function is_numeric;
use function is_string;
use function pathinfo;
use function random_bytes;
use function strlen;
use function strtolower;
use function sys_get_temp_dir;
use function tempnam;

use const PATHINFO_EXTENSION;
use const UPLOAD_ERR_CANT_WRITE;
use const UPLOAD_ERR_EXTENSION;
use const UPLOAD_ERR_FORM_SIZE;
use const UPLOAD_ERR_INI_SIZE;
use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_NO_TMP_DIR;
use const UPLOAD_ERR_OK;
use const UPLOAD_ERR_PARTIAL;

/**
 * A file uploaded with a multipart request (`$request->file('avatar')`).
 *
 * The name and type sent by the client are kept but never trusted: {@see mimeType()} and
 * {@see extension()} come from the file's contents, and {@see store()} names the file with a
 * random hash. OpenSwoole deletes the temporary file when the request ends, so store it during
 * the request.
 */
final class UploadedFile
{
    private ?string $detectedType = null;

    private bool $detected = false;

    public function __construct(
        private readonly string $path,
        private readonly string $clientName,
        private readonly ?string $clientType,
        private readonly int $error = UPLOAD_ERR_OK,
        private readonly ?int $clientSize = null,
    ) {}

    /**
     * OpenSwoole's `$request->files` as name => file or list of files (`photos[]`), in either
     * the per-file or the PHP (`$_FILES`) layout. Empty file inputs are left out.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public static function normalize(array $files): array
    {
        $out = [];
        foreach ($files as $name => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parsed = self::entry($entry);
            if ($parsed !== null) {
                $out[(string) $name] = $parsed;
            }
        }

        return $out;
    }

    /** A file for tests: `UploadedFile::fake('avatar.png', $pngBytes)`. */
    public static function fake(string $name, string $contents, ?string $clientType = null): self
    {
        $path = tempnam(sys_get_temp_dir(), 'omnitrix-upload-');
        if ($path === false || file_put_contents($path, $contents) === false) {
            throw new FilesystemException('Could not create a fake upload');
        }

        return new self($path, $name, $clientType, UPLOAD_ERR_OK, strlen($contents));
    }

    /** The name the client sent (do not use it as a storage path). */
    public function clientName(): string
    {
        return $this->clientName;
    }

    public function clientExtension(): string
    {
        return strtolower(pathinfo($this->clientName, PATHINFO_EXTENSION));
    }

    /** The type the client claimed (untrusted). */
    public function clientMimeType(): ?string
    {
        return $this->clientType;
    }

    /** The type detected from the contents (ext-fileinfo); null when it cannot be determined. */
    public function mimeType(): ?string
    {
        if (!$this->detected) {
            $this->detected = true;
            $this->detectedType = $this->isValid() ? MimeTypes::detect($this->path) : null;
        }

        return $this->detectedType;
    }

    /** Extension for the detected type (`jpg`, `pdf`, …), `bin` when unknown. */
    public function extension(): string
    {
        return MimeTypes::extension($this->mimeType()) ?? 'bin';
    }

    /** Size in bytes. */
    public function size(): int
    {
        $size = $this->isValid() ? @filesize($this->path) : false;

        return is_int($size) ? $size : ($this->clientSize ?? 0);
    }

    public function error(): int
    {
        return $this->error;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_file($this->path);
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            UPLOAD_ERR_OK => $this->isValid() ? '' : 'The uploaded file is no longer available.',
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'The file could not be saved on the server.',
            default => 'The file failed to upload.',
        };
    }

    /** The temporary file. */
    public function path(): string
    {
        return $this->path;
    }

    /** A random name with the detected extension: `3f9c…e1.jpg`. */
    public function hashName(): string
    {
        return bin2hex(random_bytes(20)) . '.' . $this->extension();
    }

    /**
     * Stores the file under `$directory` with a random name; returns its path on the disk.
     *
     * @param array{content_type?: string, visibility?: 'public'|'private'} $options
     */
    public function store(string $directory = '', ?string $disk = null, array $options = []): string
    {
        return $this->storeAs($directory, $this->hashName(), $disk, $options);
    }

    /** @param array{content_type?: string, visibility?: 'public'|'private'} $options */
    public function storeAs(string $directory, string $name, ?string $disk = null, array $options = []): string
    {
        if (!$this->isValid()) {
            throw new FilesystemException('Cannot store a failed upload: ' . $this->errorMessage());
        }
        $path = Path::join($directory, $name);
        Storage::disk($disk)->putFileAs($path, $this, $options + ['content_type' => $this->mimeType() ?? 'application/octet-stream']);

        return $path;
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return self|list<self>|null
     */
    private static function entry(array $entry): self|array|null
    {
        if (isset($entry['tmp_name']) && is_array($entry['tmp_name'])) {
            // PHP layout: every field is an array of the same keys.
            $field = static fn(string $key, int|string $i): mixed => is_array($entry[$key] ?? null) ? ($entry[$key][$i] ?? null) : null;
            $list = [];
            foreach ($entry['tmp_name'] as $i => $ignored) {
                $file = self::one(['name' => $field('name', $i) ?? '', 'type' => $field('type', $i), 'tmp_name' => $field('tmp_name', $i), 'error' => $field('error', $i) ?? UPLOAD_ERR_OK, 'size' => $field('size', $i)]);
                if ($file !== null) {
                    $list[] = $file;
                }
            }

            return $list === [] ? null : $list;
        }
        if (array_key_exists('tmp_name', $entry) || array_key_exists('error', $entry)) {
            return self::one($entry);
        }
        if (array_is_list($entry)) {
            $list = [];
            foreach ($entry as $item) {
                $file = is_array($item) ? self::one($item) : null;
                if ($file !== null) {
                    $list[] = $file;
                }
            }

            return $list === [] ? null : $list;
        }

        return null;
    }

    /** @param array<array-key, mixed> $entry */
    private static function one(array $entry): ?self
    {
        $error = $entry['error'] ?? UPLOAD_ERR_OK;
        $error = is_numeric($error) ? (int) $error : UPLOAD_ERR_EXTENSION;
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $path = $entry['tmp_name'] ?? '';
        if (!is_string($path)) {
            throw new InvalidArgumentException('Malformed upload');
        }
        $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
        $type = is_string($entry['type'] ?? null) && $entry['type'] !== '' ? $entry['type'] : null;
        $size = is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : null;

        return new self($path, $name, $type, $error, $size);
    }
}
