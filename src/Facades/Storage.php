<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use DateTimeInterface;
use Omnitrix\Contracts\Filesystem;
use Omnitrix\Filesystem\FilesystemManager;
use Omnitrix\Filesystem\MemoryDisk;

/**
 * File storage (documentation: Basics → File storage).
 *
 * ```php
 * $path = $request->file('avatar')->store('avatars', 'public');
 * Storage::disk('public')->url($path);
 * Storage::put('reports/2026-11.csv', $csv);
 * Storage::disk('s3')->temporaryUrl('invoices/42.pdf', 300);
 * $fake = Storage::fake('public'); … $fake->assertExists($path);
 * ```
 */
final class Storage extends Facade
{
    public static function manager(): FilesystemManager
    {
        return self::resolve(FilesystemManager::class);
    }

    public static function disk(?string $name = null): Filesystem
    {
        return self::manager()->disk($name);
    }

    /** Replaces the disk with an in-memory one in this worker (tests). */
    public static function fake(?string $disk = null): MemoryDisk
    {
        return self::manager()->fake($disk);
    }

    public static function exists(string $path): bool
    {
        return self::disk()->exists($path);
    }

    public static function get(string $path): string
    {
        return self::disk()->get($path);
    }

    /** @param array{content_type?: string, visibility?: 'public'|'private'} $options */
    public static function put(string $path, string $contents, array $options = []): void
    {
        self::disk()->put($path, $contents, $options);
    }

    /** @param string|list<string> $paths */
    public static function delete(string|array $paths): bool
    {
        return self::disk()->delete($paths);
    }

    /** @return list<string> */
    public static function files(string $directory = '', bool $recursive = false): array
    {
        return self::disk()->files($directory, $recursive);
    }

    public static function url(string $path): string
    {
        return self::disk()->url($path);
    }

    public static function temporaryUrl(string $path, int|DateTimeInterface $expiration): string
    {
        return self::disk()->temporaryUrl($path, $expiration);
    }
}
