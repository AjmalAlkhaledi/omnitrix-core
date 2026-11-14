<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Cache\Cache as CacheService;

/**
 * Application cache (documentation: Async & services → Redis and cache).
 *
 * ```php
 * Cache::set('user:10', $user, 3600);
 * $user = Cache::get('user:10');
 * Cache::delete('user:10');
 * ```
 */
final class Cache extends Facade
{
    public static function store(): CacheService
    {
        return self::resolve(CacheService::class);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::store()->get($key, $default);
    }

    public static function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return self::store()->set($key, $value, $ttl);
    }

    public static function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        return self::store()->add($key, $value, $ttl);
    }

    public static function has(string $key): bool
    {
        return self::store()->has($key);
    }

    public static function delete(string ...$keys): int
    {
        return self::store()->delete(...$keys);
    }

    /**
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public static function remember(string $key, ?int $ttl, callable $compute): mixed
    {
        return self::store()->remember($key, $ttl, $compute);
    }

    public static function increment(string $key, int $by = 1): int
    {
        return self::store()->increment($key, $by);
    }
}
