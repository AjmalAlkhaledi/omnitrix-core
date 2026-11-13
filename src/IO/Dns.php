<?php

declare(strict_types=1);

namespace Omnitrix\Core\IO;

use OpenSwoole\Coroutine\System;

use function count;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function microtime;

use const AF_INET;
use const FILTER_VALIDATE_IP;
use const SOCK_STREAM;
use const STREAM_IPPROTO_TCP;

/**
 * Coroutine DNS resolution (c-ares via `Coroutine\System`) with a small per-worker TTL cache.
 * IP literals are returned unchanged. Lookups suspend only the calling coroutine.
 */
final class Dns
{
    /** @var array<string, array{list<string>, float}> host => [addresses, expires] */
    private array $cache = [];

    public function __construct(private readonly float $ttl = 60.0, private readonly int $maxEntries = 1024) {}

    /** @throws IoException when the host cannot be resolved */
    public function resolve(string $host, float $timeout = 5.0): string
    {
        return $this->resolveAll($host, $timeout)[0];
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws IoException
     */
    public function resolveAll(string $host, float $timeout = 5.0): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $cached = $this->cache[$host] ?? null;
        if ($cached !== null && $cached[1] > microtime(true) && $cached[0] !== []) {
            return $cached[0];
        }
        $addresses = System::getaddrinfo($host, AF_INET, SOCK_STREAM, STREAM_IPPROTO_TCP, '', $timeout);
        $list = [];
        if (is_array($addresses)) {
            foreach ($addresses as $address) {
                if (is_string($address) && !in_array($address, $list, true)) {
                    $list[] = $address;
                }
            }
        }
        if ($list === []) {
            $single = System::dnsLookup($host, $timeout);
            if (!is_string($single) || $single === '') {
                throw new IoException("Could not resolve host '{$host}'");
            }
            $list = [$single];
        }
        if (count($this->cache) >= $this->maxEntries) {
            $this->cache = [];
        }
        $this->cache[$host] = [$list, microtime(true) + $this->ttl];

        return $list;
    }

    public function clear(): void
    {
        $this->cache = [];
        System::clearDNSCache();
    }
}
