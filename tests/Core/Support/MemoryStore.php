<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Support;

use Omnitrix\Core\Cache\KeyValueStoreInterface;

use function array_key_exists;
use function is_numeric;
use function microtime;

/** In-memory {@see KeyValueStoreInterface} with TTL semantics for unit tests. */
final class MemoryStore implements KeyValueStoreInterface
{
    /** @var array<string, array{string, float|null}> */
    private array $data = [];

    public function get(string $key): ?string
    {
        $entry = $this->data[$key] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry[1] !== null && $entry[1] <= microtime(true)) {
            unset($this->data[$key]);

            return null;
        }

        return $entry[0];
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        $this->data[$key] = [$value, $ttl === null ? null : microtime(true) + $ttl];

        return true;
    }

    public function setIfAbsent(string $key, string $value, ?int $ttl = null): bool
    {
        if ($this->get($key) !== null) {
            return false;
        }

        return $this->set($key, $value, $ttl);
    }

    public function getMany(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->get($key);
        }

        return $out;
    }

    public function delete(string ...$keys): int
    {
        $n = 0;
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->data)) {
                unset($this->data[$key]);
                $n++;
            }
        }

        return $n;
    }

    public function exists(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function increment(string $key, int $by = 1): int
    {
        $current = $this->get($key);
        $value = (is_numeric($current) ? (int) $current : 0) + $by;
        $this->data[$key] = [(string) $value, $this->data[$key][1] ?? null];

        return $value;
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->increment($key, -$by);
    }

    public function expire(string $key, int $seconds): bool
    {
        if (!array_key_exists($key, $this->data)) {
            return false;
        }
        $this->data[$key][1] = microtime(true) + $seconds;

        return true;
    }

    public function ttl(string $key): int
    {
        $entry = $this->data[$key] ?? null;
        if ($entry === null) {
            return -2;
        }

        return $entry[1] === null ? -1 : (int) ($entry[1] - microtime(true));
    }
}
