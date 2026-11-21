<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_is_list;
use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function preg_match;

/** Validated `redis` configuration section. */
final readonly class RedisConfig
{
    private const array KNOWN = ['host', 'port', 'cluster', 'username', 'password', 'database', 'timeout', 'read_timeout', 'prefix', 'pool'];

    private const array KNOWN_POOL = ['max', 'acquire_timeout', 'idle_timeout', 'validation_interval'];

    private function __construct(
        public string $host,
        public int $port,
        public ?string $username,
        public ?string $password,
        public int $database,
        /** Connect timeout, seconds. */
        public float $timeout,
        /** Read timeout, seconds (bounds blocking commands such as BLPOP; 0 = unlimited). */
        public float $readTimeout,
        /** Key prefix applied by phpredis to every command. */
        public string $prefix,
        public int $poolMax,
        public float $poolAcquireTimeout,
        public float $poolIdleTimeout,
        public float $poolValidationInterval,
        /**
         * Redis Cluster seed nodes (`host:port`); empty for a single server. With seeds, `host`
         * and `port` are ignored and `database` must be 0.
         *
         * @var list<string>
         */
        public array $cluster = [],
    ) {}

    public function isCluster(): bool
    {
        return $this->cluster !== [];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at('redis.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $pool = $config['pool'] ?? [];
        if (!is_array($pool)) {
            throw InvalidConfigurationException::at('redis.pool', 'expected an array');
        }
        foreach (array_keys($pool) as $key) {
            if (!in_array((string) $key, self::KNOWN_POOL, true)) {
                throw InvalidConfigurationException::at('redis.pool.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN_POOL));
            }
        }
        /** @var array<string, mixed> $pool */
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;
        if (($username !== null && !is_string($username)) || ($password !== null && !is_string($password))) {
            throw InvalidConfigurationException::at('redis.password', 'expected string or null');
        }
        // An empty value (e.g. `REDIS_PASSWORD=` in an environment file) means "no credentials".
        $username = $username === '' ? null : $username;
        $password = $password === '' ? null : $password;

        $cluster = $config['cluster'] ?? [];
        if (!is_array($cluster) || !array_is_list($cluster)) {
            throw InvalidConfigurationException::at('redis.cluster', 'expected a list of "host:port" seed nodes');
        }
        $seeds = [];
        foreach ($cluster as $i => $seed) {
            if (!is_string($seed) || preg_match('/^[^\s:]+:\d{1,5}$/', $seed) !== 1) {
                throw InvalidConfigurationException::at("redis.cluster.{$i}", 'expected "host:port"');
            }
            $seeds[] = $seed;
        }
        $database = self::int($config, 'database', 0, 0, 255);
        if ($seeds !== [] && $database !== 0) {
            throw InvalidConfigurationException::at('redis.database', 'Redis Cluster has only database 0');
        }

        return new self(
            host: self::string($config, 'host', '127.0.0.1'),
            port: self::int($config, 'port', 6379, 1, 65535),
            username: $username,
            password: $password,
            database: $database,
            timeout: self::float($config, 'timeout', 2.0, 0.001, 300.0),
            readTimeout: self::float($config, 'read_timeout', 5.0, 0.0, 86400.0),
            prefix: self::string($config, 'prefix', '', true),
            poolMax: self::int($pool, 'max', 16, 1, 10000, 'pool.'),
            poolAcquireTimeout: self::float($pool, 'acquire_timeout', 3.0, 0.001, 3600.0, 'pool.'),
            poolIdleTimeout: self::float($pool, 'idle_timeout', 120.0, 0.0, 86400.0, 'pool.'),
            poolValidationInterval: self::float($pool, 'validation_interval', 30.0, 0.0, 86400.0, 'pool.'),
            cluster: $seeds,
        );
    }

    /** @param array<string, mixed> $c */
    private static function string(array $c, string $key, string $default, bool $allowEmpty = false): string
    {
        $v = $c[$key] ?? $default;
        if (!is_string($v) || (!$allowEmpty && $v === '')) {
            throw InvalidConfigurationException::at("redis.{$key}", 'expected non-empty string');
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function int(array $c, string $key, int $default, int $min, int $max, string $prefix = ''): int
    {
        $v = $c[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("redis.{$prefix}{$key}", "expected integer between {$min} and {$max}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function float(array $c, string $key, float $default, float $min, float $max, string $prefix = ''): float
    {
        $v = $c[$key] ?? $default;
        if (is_int($v)) {
            $v = (float) $v;
        }
        if (!is_float($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("redis.{$prefix}{$key}", "expected number between {$min} and {$max}");
        }

        return $v;
    }
}
