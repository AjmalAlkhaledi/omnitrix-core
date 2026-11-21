<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem;

use InvalidArgumentException;
use Omnitrix\Contracts\Filesystem as Disk;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Filesystem\S3\S3Disk;
use Omnitrix\Routing\UrlGenerator;

use function array_keys;
use function array_map;
use function array_values;
use function is_array;
use function is_string;
use function rtrim;
use function str_starts_with;

/**
 * The configured disks (`config/filesystems.php`), created on first use in each worker.
 *
 * ```php
 * 'default' => 'local',
 * 'disks' => [
 *     'local' => ['driver' => 'local', 'root' => __DIR__ . '/../storage/app'],
 *     'public' => ['driver' => 'local', 'root' => __DIR__ . '/../storage/app/public', 'url' => '/storage', 'visibility' => 'public'],
 *     's3' => ['driver' => 's3', 'key' => …, 'secret' => …, 'region' => …, 'bucket' => …],
 * ],
 * ```
 */
final class FilesystemManager
{
    /** @var array<string, Disk> */
    private array $disks = [];

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config, private readonly ?HttpClient $http = null, private readonly ?UrlGenerator $urls = null, private readonly string $appUrl = '') {}

    public function defaultDisk(): string
    {
        $default = $this->config['default'] ?? 'local';

        return is_string($default) ? $default : 'local';
    }

    public function disk(?string $name = null): Disk
    {
        $name ??= $this->defaultDisk();

        return $this->disks[$name] ??= $this->make($name);
    }

    /** Replaces a disk with an empty in-memory one (tests). */
    public function fake(?string $name = null): MemoryDisk
    {
        $name ??= $this->defaultDisk();
        $url = $this->diskConfig($name, required: false)['url'] ?? '/storage';
        $fake = new MemoryDisk($name, self::absolute(is_string($url) ? $url : '/storage', $this->appUrl));
        $this->disks[$name] = $fake;

        return $fake;
    }

    /** @return list<string> */
    public function names(): array
    {
        $disks = $this->config['disks'] ?? [];

        return is_array($disks) ? array_values(array_map('strval', array_keys($disks))) : [];
    }

    private function make(string $name): Disk
    {
        $config = $this->diskConfig($name);
        $driver = $config['driver'] ?? 'local';

        return match ($driver) {
            'local' => new LocalDisk(
                $name,
                is_string($config['root'] ?? null) ? $config['root'] : throw new InvalidArgumentException("Disk '{$name}': 'root' is required"),
                is_string($config['url'] ?? null) ? self::absolute($config['url'], $this->appUrl) : null,
                is_string($config['visibility'] ?? null) ? $config['visibility'] : 'private',
                $this->urls,
            ),
            's3' => new S3Disk($name, $config, $this->http ?? throw new InvalidArgumentException('The s3 driver needs the HTTP client')),
            'memory' => new MemoryDisk($name, self::absolute(is_string($config['url'] ?? null) ? $config['url'] : '/storage', $this->appUrl)),
            default => throw new InvalidArgumentException("Disk '{$name}': unknown driver '" . (is_string($driver) ? $driver : '?') . "' (local, s3, memory)"),
        };
    }

    /** @return array<string, mixed> */
    private function diskConfig(string $name, bool $required = true): array
    {
        $disks = $this->config['disks'] ?? [];
        $config = is_array($disks) ? ($disks[$name] ?? null) : null;
        if (!is_array($config)) {
            if ($required) {
                throw new InvalidArgumentException("Storage disk '{$name}' is not configured (config/filesystems.php)");
            }

            return [];
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    private static function absolute(string $url, string $appUrl): string
    {
        return str_starts_with($url, '/') && $appUrl !== '' ? rtrim($appUrl, '/') . $url : $url;
    }
}
