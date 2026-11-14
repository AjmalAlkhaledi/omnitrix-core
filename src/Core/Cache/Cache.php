<?php

declare(strict_types=1);

namespace Omnitrix\Core\Cache;

use InvalidArgumentException;
use JsonException;

use function json_decode;
use function json_encode;
use function preg_match;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Application cache over a key-value store.
 *
 * Values are JSON-encoded (scalars, arrays, JsonSerializable); objects are not reconstructed on
 * read, which keeps deserialization free of `unserialize()` and its security hazards. Keys are
 * namespaced with the configured prefix.
 */
final class Cache
{
    public function __construct(private readonly KeyValueStoreInterface $store, private readonly string $prefix = 'cache:', private readonly int $defaultTtl = 3600) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $raw = $this->store->get($this->key($key));

        return $raw === null ? $default : $this->decode($raw, $key);
    }

    /** @param int|null $ttl seconds; null = default TTL; 0 = no expiry */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;

        return $this->store->set($this->key($key), $this->encode($value), $ttl > 0 ? $ttl : null);
    }

    /** Stores only when the key is absent (atomic). */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $ttl ??= $this->defaultTtl;

        return $this->store->setIfAbsent($this->key($key), $this->encode($value), $ttl > 0 ? $ttl : null);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, 0);
    }

    public function has(string $key): bool
    {
        return $this->store->exists($this->key($key));
    }

    public function delete(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }
        $prefixed = [];
        foreach ($keys as $key) {
            $prefixed[] = $this->key($key);
        }

        return $this->store->delete(...$prefixed);
    }

    /**
     * @param list<string> $keys
     * @return array<string, mixed> key => value (missing keys omitted)
     */
    public function getMany(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $prefixed = [];
        foreach ($keys as $key) {
            $prefixed[] = $this->key($key);
        }
        $values = $this->store->getMany($prefixed);
        $out = [];
        foreach ($keys as $i => $key) {
            $raw = $values[$i] ?? null;
            if ($raw !== null) {
                $out[$key] = $this->decode($raw, $key);
            }
        }

        return $out;
    }

    /**
     * Returns the cached value or computes, stores and returns it.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public function remember(string $key, ?int $ttl, callable $compute): mixed
    {
        $raw = $this->store->get($this->key($key));
        if ($raw !== null) {
            /** @var T $decoded */
            $decoded = $this->decode($raw, $key);

            return $decoded;
        }
        $value = $compute();
        $this->set($key, $value, $ttl);

        return $value;
    }

    public function increment(string $key, int $by = 1): int
    {
        return $this->store->increment($this->key($key), $by);
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->store->decrement($this->key($key), $by);
    }

    public function ttl(string $key): int
    {
        return $this->store->ttl($this->key($key));
    }

    public function key(string $key): string
    {
        if ($key === '' || preg_match('/[\s]/', $key) === 1) {
            throw new InvalidArgumentException("Invalid cache key '{$key}'");
        }

        return $this->prefix . $key;
    }

    private function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Cache value cannot be encoded: ' . $e->getMessage(), 0, $e);
        }
    }

    private function decode(string $raw, string $key): mixed
    {
        // Counters written by increment() are plain integers, not JSON documents of ours, but
        // json_decode handles both representations.
        try {
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $raw; // a value written by another system: hand back the raw string
        }
    }
}
