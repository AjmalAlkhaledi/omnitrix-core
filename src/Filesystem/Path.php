<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use InvalidArgumentException;

use function explode;
use function implode;
use function in_array;
use function preg_match;
use function str_contains;
use function trim;

/** @internal path rules shared by every disk */
final class Path
{
    /**
     * `a//b/./c/` → `a/b/c`. Rejects `..`, NUL bytes, backslashes and control characters, so
     * no path leaves the disk's root whatever the caller passes (for example a client file name).
     */
    public static function normalize(string $path, bool $allowEmpty = false): string
    {
        if (str_contains($path, "\0") || str_contains($path, '\\') || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new InvalidArgumentException('Invalid characters in storage path');
        }
        $parts = [];
        foreach (explode('/', trim($path, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new InvalidArgumentException("Storage paths may not contain '..': {$path}");
            }
            $parts[] = $part;
        }
        $normalized = implode('/', $parts);
        if ($normalized === '' && !$allowEmpty) {
            throw new InvalidArgumentException('Empty storage path');
        }

        return $normalized;
    }

    public static function join(string $directory, string $name): string
    {
        $directory = self::normalize($directory, allowEmpty: true);
        $name = self::normalize($name);

        return $directory === '' ? $name : $directory . '/' . $name;
    }

    public static function visibility(mixed $value): string
    {
        if (!in_array($value, ['public', 'private'], true)) {
            throw new InvalidArgumentException("Visibility must be 'public' or 'private'");
        }

        return $value;
    }
}
