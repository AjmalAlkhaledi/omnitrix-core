<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use finfo;

use function array_search;
use function class_exists;
use function is_string;
use function strrpos;
use function strtolower;
use function substr;

use const FILEINFO_MIME_TYPE;

/** MIME types detected from file contents (ext-fileinfo) and their usual extensions. */
final class MimeTypes
{
    /** @var array<string, string> type => extension */
    private const array EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/avif' => 'avif', 'image/heic' => 'heic', 'image/bmp' => 'bmp', 'image/x-ms-bmp' => 'bmp',
        'image/tiff' => 'tiff', 'image/svg+xml' => 'svg', 'image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico',
        'application/pdf' => 'pdf', 'application/zip' => 'zip', 'application/gzip' => 'gz', 'application/x-gzip' => 'gz',
        'application/x-tar' => 'tar', 'application/x-7z-compressed' => '7z', 'application/json' => 'json',
        'application/xml' => 'xml', 'text/xml' => 'xml', 'text/plain' => 'txt', 'text/csv' => 'csv', 'text/html' => 'html',
        'text/markdown' => 'md', 'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt', 'audio/mpeg' => 'mp3', 'audio/ogg' => 'ogg',
        'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/flac' => 'flac', 'audio/mp4' => 'm4a', 'video/mp4' => 'mp4',
        'video/webm' => 'webm', 'video/quicktime' => 'mov', 'video/x-matroska' => 'mkv', 'font/woff2' => 'woff2',
        'font/woff' => 'woff', 'application/wasm' => 'wasm', 'application/octet-stream' => 'bin',
    ];

    /** @var array<string, string> extension => type (for serving files by name) */
    private const array BY_EXTENSION = ['jpeg' => 'image/jpeg', 'htm' => 'text/html', 'js' => 'text/javascript', 'css' => 'text/css', 'txt' => 'text/plain'];

    private static ?finfo $finfo = null;

    /** The type from the contents; null without ext-fileinfo or for unreadable files. */
    public static function detect(string $file): ?string
    {
        if (!class_exists(finfo::class)) {
            return null;
        }
        self::$finfo ??= new finfo(FILEINFO_MIME_TYPE);
        $type = @self::$finfo->file($file);

        return is_string($type) && $type !== '' ? strtolower($type) : null;
    }

    public static function detectBuffer(string $contents): ?string
    {
        if (!class_exists(finfo::class)) {
            return null;
        }
        self::$finfo ??= new finfo(FILEINFO_MIME_TYPE);
        $type = self::$finfo->buffer($contents);

        return is_string($type) && $type !== '' ? strtolower($type) : null;
    }

    public static function extension(?string $type): ?string
    {
        return $type === null ? null : (self::EXTENSIONS[$type] ?? null);
    }

    /** The type for a file name, for serving stored files. */
    public static function forPath(string $path): string
    {
        $dot = strrpos($path, '.');
        $extension = $dot === false ? '' : strtolower(substr($path, $dot + 1));
        if ($extension === '') {
            return 'application/octet-stream';
        }
        if (isset(self::BY_EXTENSION[$extension])) {
            return self::BY_EXTENSION[$extension];
        }
        $type = array_search($extension, self::EXTENSIONS, true);

        return is_string($type) ? $type : 'application/octet-stream';
    }
}
