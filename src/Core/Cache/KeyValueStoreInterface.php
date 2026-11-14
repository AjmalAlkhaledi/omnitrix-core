<?php

declare(strict_types=1);

namespace Omnitrix\Core\Cache;

/** Minimal string store the cache is built on (Redis in production, in-memory in tests). */
interface KeyValueStoreInterface
{
    public function get(string $key): ?string;

    /** @param int|null $ttl seconds */
    public function set(string $key, string $value, ?int $ttl = null): bool;

    public function setIfAbsent(string $key, string $value, ?int $ttl = null): bool;

    /**
     * @param list<string> $keys
     * @return list<string|null>
     */
    public function getMany(array $keys): array;

    public function delete(string ...$keys): int;

    public function exists(string $key): bool;

    public function increment(string $key, int $by = 1): int;

    public function decrement(string $key, int $by = 1): int;

    public function expire(string $key, int $seconds): bool;

    public function ttl(string $key): int;
}
