<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Redis\RedisClient;
use Redis as PhpRedis;

/**
 * Redis access (see docs/redis.md). Typed helpers for common commands; `command()` for the
 * rest; `withConnection()` for anything on the raw phpredis handle.
 */
final class Redis extends Facade
{
    public static function client(): RedisClient
    {
        return self::resolve(RedisClient::class);
    }

    public static function get(string $key): ?string
    {
        return self::client()->get($key);
    }

    public static function set(string $key, string $value, ?int $ttl = null): bool
    {
        return self::client()->set($key, $value, $ttl);
    }

    public static function delete(string ...$keys): int
    {
        return self::client()->delete(...$keys);
    }

    public static function increment(string $key, int $by = 1): int
    {
        return self::client()->increment($key, $by);
    }

    public static function expire(string $key, int $seconds): bool
    {
        return self::client()->expire($key, $seconds);
    }

    /** @param list<string|int|float> $args */
    public static function command(string $name, array $args = []): mixed
    {
        return self::client()->command($name, $args);
    }

    /**
     * @template T
     * @param callable(PhpRedis): T $fn
     * @return T
     */
    public static function withConnection(callable $fn): mixed
    {
        return self::client()->withConnection($fn);
    }

    /**
     * @param callable(PhpRedis): void $commands
     * @return list<mixed>
     */
    public static function pipeline(callable $commands): array
    {
        return self::client()->pipeline($commands);
    }

    /**
     * @param callable(PhpRedis): void $commands
     * @return list<mixed>
     */
    public static function transaction(callable $commands): array
    {
        return self::client()->transaction($commands);
    }

    public static function publish(string $channel, string $message): int
    {
        return self::client()->publish($channel, $message);
    }
}
