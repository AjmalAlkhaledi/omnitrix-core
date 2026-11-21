<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

final class FileNotFoundException extends FilesystemException
{
    public static function at(string $disk, string $path): self
    {
        return new self("File not found on disk '{$disk}': {$path}");
    }
}
