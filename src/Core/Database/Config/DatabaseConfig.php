<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Config;

use Omnitrix\Core\Database\Cache\CacheConfig;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/** Validated settings of one named database connection (the `database` config section). */
final readonly class DatabaseConfig
{
    private const array KNOWN = ['driver', 'host', 'port', 'unix_socket', 'database', 'username', 'password', 'charset', 'collation', 'timezone', 'options', 'pool', 'connect_timeout', 'statement_timeout', 'strict', 'cache'];

    private const array KNOWN_POOL = ['min', 'max', 'acquire_timeout', 'idle_timeout', 'max_lifetime', 'validation_interval', 'eviction_interval'];

    /** @param array<int, mixed> $options PDO driver options */
    private function __construct(
        public string $name,
        public string $driver,
        public string $host,
        public int $port,
        public ?string $unixSocket,
        public string $database,
        public string $username,
        public string $password,
        public string $charset,
        public ?string $collation,
        public ?string $timezone,
        public array $options,
        public float $connectTimeout,
        public float $statementTimeout,
        public bool $strict,
        public PoolConfig $pool,
        public CacheConfig $cache,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config, string $name = 'default'): self
    {
        $path = "database.{$name}";
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at("{$path}.{$key}", 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $driver = self::str($config, 'driver', 'mysql', $path);
        if (!in_array($driver, ['mysql', 'mariadb'], true)) {
            throw InvalidConfigurationException::at("{$path}.driver", "unsupported driver '{$driver}' (mysql, mariadb)");
        }
        $database = self::str($config, 'database', '', $path);
        if ($database === '') {
            throw InvalidConfigurationException::at("{$path}.database", 'database name is required');
        }
        $poolRaw = $config['pool'] ?? [];
        if (!is_array($poolRaw)) {
            throw InvalidConfigurationException::at("{$path}.pool", 'expected an array');
        }
        foreach (array_keys($poolRaw) as $key) {
            if (!in_array((string) $key, self::KNOWN_POOL, true)) {
                throw InvalidConfigurationException::at("{$path}.pool.{$key}", 'unknown option; known options are ' . implode(', ', self::KNOWN_POOL));
            }
        }
        /** @var array<string, mixed> $poolRaw */
        $pool = new PoolConfig(
            min: self::int($poolRaw, 'min', 1, 0, 1000, "{$path}.pool"),
            max: self::int($poolRaw, 'max', 10, 1, 1000, "{$path}.pool"),
            acquireTimeout: self::float($poolRaw, 'acquire_timeout', 5.0, 0.001, 3600.0, "{$path}.pool"),
            idleTimeout: self::float($poolRaw, 'idle_timeout', 300.0, 0.0, 86400.0, "{$path}.pool"),
            maxLifetime: self::float($poolRaw, 'max_lifetime', 1800.0, 0.0, 86400.0, "{$path}.pool"),
            validationInterval: self::float($poolRaw, 'validation_interval', 30.0, 0.0, 86400.0, "{$path}.pool"),
            evictionInterval: self::float($poolRaw, 'eviction_interval', 10.0, 0.1, 3600.0, "{$path}.pool"),
        );
        if ($pool->min > $pool->max) {
            throw InvalidConfigurationException::at("{$path}.pool.min", 'must be <= pool.max');
        }
        $options = $config['options'] ?? [];
        if (!is_array($options)) {
            throw InvalidConfigurationException::at("{$path}.options", 'expected an array of PDO options');
        }
        $typedOptions = [];
        foreach ($options as $k => $v) {
            if (!is_int($k)) {
                throw InvalidConfigurationException::at("{$path}.options", 'keys must be PDO::ATTR_* integers');
            }
            $typedOptions[$k] = $v;
        }
        $unixSocket = $config['unix_socket'] ?? null;
        if ($unixSocket !== null && (!is_string($unixSocket) || $unixSocket === '')) {
            throw InvalidConfigurationException::at("{$path}.unix_socket", 'expected non-empty string or null');
        }
        $collation = $config['collation'] ?? null;
        $timezone = $config['timezone'] ?? null;

        return new self(
            name: $name,
            driver: $driver,
            host: self::str($config, 'host', '127.0.0.1', $path),
            port: self::int($config, 'port', 3306, 1, 65535, $path),
            unixSocket: $unixSocket,
            database: $database,
            username: self::str($config, 'username', 'root', $path),
            password: self::str($config, 'password', '', $path),
            charset: self::str($config, 'charset', 'utf8mb4', $path),
            collation: is_string($collation) ? $collation : null,
            timezone: is_string($timezone) ? $timezone : null,
            options: $typedOptions,
            connectTimeout: self::float($config, 'connect_timeout', 3.0, 0.1, 300.0, $path),
            statementTimeout: self::float($config, 'statement_timeout', 30.0, 0.0, 86400.0, $path),
            strict: self::bool($config, 'strict', true, $path),
            pool: $pool,
            cache: CacheConfig::fromArray($config['cache'] ?? null, $name),
        );
    }

    /** @param array<string, mixed> $c */
    private static function str(array $c, string $key, string $default, string $path): string
    {
        $v = $c[$key] ?? $default;
        if (!is_string($v)) {
            throw InvalidConfigurationException::at("{$path}.{$key}", 'expected string');
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function int(array $c, string $key, int $default, int $min, int $max, string $path): int
    {
        $v = $c[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("{$path}.{$key}", "expected integer between {$min} and {$max}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function float(array $c, string $key, float $default, float $min, float $max, string $path): float
    {
        $v = $c[$key] ?? $default;
        if (is_int($v)) {
            $v = (float) $v;
        }
        if (!is_float($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("{$path}.{$key}", "expected number between {$min} and {$max}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function bool(array $c, string $key, bool $default, string $path): bool
    {
        $v = $c[$key] ?? $default;
        if (!is_bool($v)) {
            throw InvalidConfigurationException::at("{$path}.{$key}", 'expected boolean');
        }

        return $v;
    }
}
