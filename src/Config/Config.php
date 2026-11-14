<?php

declare(strict_types=1);

namespace Omnitrix\Config;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_key_exists;
use function basename;
use function explode;
use function glob;
use function is_array;
use function is_dir;
use function is_string;
use function sort;

/**
 * Loads a configuration directory: every `*.php` file returns an array and becomes the section
 * named after the file (`config/database.php` → `database`). The result is the array passed to
 * {@see \Omnitrix\Application::create()}.
 *
 * ```php
 * $app = Application::create(Config::load(__DIR__ . '/config'));
 * ```
 */
final class Config
{
    private function __construct() {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidConfigurationException when the directory is missing or a file does not return an array
     */
    public static function load(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new InvalidConfigurationException("Configuration directory not found: {$directory}");
        }
        $files = glob($directory . '/*.php');
        $files = $files === false ? [] : $files;
        sort($files);
        $config = [];
        foreach ($files as $file) {
            $section = basename($file, '.php');
            $value = (static fn(string $path): mixed => require $path)($file);
            if (!is_array($value)) {
                throw new InvalidConfigurationException("Configuration file {$file} must return an array");
            }
            $config[$section] = $value;
        }

        return $config;
    }

    /**
     * Reads a dotted path (`queue.jobs`) from a configuration array.
     *
     * @param array<string, mixed> $config
     */
    public static function get(array $config, string $path, mixed $default = null): mixed
    {
        $value = $config;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    public static function string(array $config, string $path, string $default): string
    {
        $value = self::get($config, $path, $default);

        return is_string($value) ? $value : $default;
    }
}
