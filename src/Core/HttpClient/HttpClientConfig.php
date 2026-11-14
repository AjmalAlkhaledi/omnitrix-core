<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;

use function array_keys;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_float;
use function is_int;
use function is_string;

/** Validated `http_client` configuration section (defaults for every outbound request). */
final readonly class HttpClientConfig
{
    private const array KNOWN = ['timeout', 'connect_timeout', 'max_redirects', 'max_response_size', 'verify_tls', 'ca_file', 'user_agent', 'retries', 'retry_on_timeout', 'retry_base_delay_ms', 'max_concurrent', 'pool', 'http2'];

    private const array KNOWN_POOL = ['max_per_host', 'idle_timeout', 'acquire_timeout'];

    private function __construct(
        /** Seconds to wait for the response after the request was sent. */
        public float $timeout,
        public float $connectTimeout,
        /** 0 disables following redirects. */
        public int $maxRedirects,
        /** Bytes; larger bodies raise ResponseTooLargeException (use download() for big files). */
        public int $maxResponseSize,
        public bool $verifyTls,
        public ?string $caFile,
        public string $userAgent,
        /** Retries for idempotent requests on connection failures (0 = none). */
        public int $retries,
        public bool $retryOnTimeout,
        public int $retryBaseDelayMs,
        /** Global cap on in-flight outbound requests per worker (backpressure). */
        public int $maxConcurrent,
        public int $poolMaxPerHost,
        public float $poolIdleTimeout,
        public float $poolAcquireTimeout,
        /** Use HTTP/2 (prior knowledge for http://, ALPN for https://) by default. */
        public bool $http2,
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException
     */
    public static function fromArray(array $config): self
    {
        foreach (array_keys($config) as $key) {
            if (!in_array((string) $key, self::KNOWN, true)) {
                throw InvalidConfigurationException::at('http_client.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN));
            }
        }
        $pool = $config['pool'] ?? [];
        if (!is_array($pool)) {
            throw InvalidConfigurationException::at('http_client.pool', 'expected an array');
        }
        foreach (array_keys($pool) as $key) {
            if (!in_array((string) $key, self::KNOWN_POOL, true)) {
                throw InvalidConfigurationException::at('http_client.pool.' . $key, 'unknown option; known options are ' . implode(', ', self::KNOWN_POOL));
            }
        }
        /** @var array<string, mixed> $pool */
        $caFile = $config['ca_file'] ?? null;
        if ($caFile !== null && (!is_string($caFile) || !is_file($caFile))) {
            throw InvalidConfigurationException::at('http_client.ca_file', 'expected a readable PEM bundle path or null');
        }

        return new self(
            timeout: self::float($config, 'timeout', 30.0, 0.001, 3600.0),
            connectTimeout: self::float($config, 'connect_timeout', 5.0, 0.001, 300.0),
            maxRedirects: self::int($config, 'max_redirects', 5, 0, 50),
            maxResponseSize: self::int($config, 'max_response_size', 16 * 1024 * 1024, 1, 1024 * 1024 * 1024),
            verifyTls: self::bool($config, 'verify_tls', true),
            caFile: $caFile,
            userAgent: self::string($config, 'user_agent', 'Omnitrix/0.1'),
            retries: self::int($config, 'retries', 0, 0, 10),
            retryOnTimeout: self::bool($config, 'retry_on_timeout', false),
            retryBaseDelayMs: self::int($config, 'retry_base_delay_ms', 50, 0, 60000),
            maxConcurrent: self::int($config, 'max_concurrent', 256, 1, 100000),
            poolMaxPerHost: self::int($pool, 'max_per_host', 32, 1, 10000, 'pool.'),
            poolIdleTimeout: self::float($pool, 'idle_timeout', 60.0, 0.0, 86400.0, 'pool.'),
            poolAcquireTimeout: self::float($pool, 'acquire_timeout', 5.0, 0.001, 3600.0, 'pool.'),
            http2: self::bool($config, 'http2', false),
        );
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /** @param array<string, mixed> $c */
    private static function float(array $c, string $key, float $default, float $min, float $max, string $prefix = ''): float
    {
        $v = $c[$key] ?? $default;
        if (is_int($v)) {
            $v = (float) $v;
        }
        if (!is_float($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("http_client.{$prefix}{$key}", "expected number between {$min} and {$max}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function int(array $c, string $key, int $default, int $min, int $max, string $prefix = ''): int
    {
        $v = $c[$key] ?? $default;
        if (!is_int($v) || $v < $min || $v > $max) {
            throw InvalidConfigurationException::at("http_client.{$prefix}{$key}", "expected integer between {$min} and {$max}");
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function bool(array $c, string $key, bool $default): bool
    {
        $v = $c[$key] ?? $default;
        if (!is_bool($v)) {
            throw InvalidConfigurationException::at("http_client.{$key}", 'expected boolean');
        }

        return $v;
    }

    /** @param array<string, mixed> $c */
    private static function string(array $c, string $key, string $default): string
    {
        $v = $c[$key] ?? $default;
        if (!is_string($v) || $v === '') {
            throw InvalidConfigurationException::at("http_client.{$key}", 'expected non-empty string');
        }

        return $v;
    }
}
