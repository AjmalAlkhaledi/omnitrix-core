<?php

declare(strict_types=1);

namespace Omnitrix\Config;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_key_exists;
use function explode;
use function file;
use function getenv;
use function is_file;
use function is_string;
use function preg_match;
use function putenv;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const FILE_IGNORE_NEW_LINES;

/**
 * Environment variables for configuration. Read once at boot (in the master) to build the
 * config array; never read per request.
 *
 * `.env` support is intentionally minimal: `KEY=value`, `KEY="quoted value"`, comments with `#`.
 * Real environment variables always win over the file (12-factor).
 */
final class Env
{
    private function __construct() {}

    /** Loads a .env file into the process environment without overriding existing variables. */
    public static function load(string $path): int
    {
        if (!is_file($path)) {
            return 0;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $loaded = 0;
        foreach ($lines === false ? [] : $lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $m) !== 1) {
                throw new InvalidConfigurationException("Malformed line " . ($number + 1) . " in {$path}");
            }
            [$name, $value] = [$m[1], $m[2]];
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            } else {
                $value = trim(explode(' #', $value, 2)[0]);
            }
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $loaded++;
            }
        }

        return $loaded;
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);

        return is_string($value) ? $value : $default;
    }

    /** @throws InvalidConfigurationException when missing or empty */
    public static function required(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            throw new InvalidConfigurationException("Required environment variable {$name} is not set");
        }

        return $value;
    }

    public static function int(string $name, int $default): int
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidConfigurationException("Environment variable {$name} must be an integer");
        }

        return (int) $value;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            return $default;
        }
        $map = ['1' => true, 'true' => true, 'yes' => true, 'on' => true, '0' => false, 'false' => false, 'no' => false, 'off' => false];
        $key = strtolower($value);
        if (!array_key_exists($key, $map)) {
            throw new InvalidConfigurationException("Environment variable {$name} must be a boolean");
        }

        return $map[$key];
    }
}
