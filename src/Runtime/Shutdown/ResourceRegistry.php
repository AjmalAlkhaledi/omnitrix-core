<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Shutdown;

use InvalidArgumentException;
use Throwable;

use function array_keys;
use function count;
use function uasort;

/**
 * Tracks worker-scoped resources (pools, sockets, timers, temp files) and releases them in a
 * deterministic order on worker stop / shutdown.
 *
 * Higher priority releases first (e.g. HTTP clients at 100 before DB pools at 50 before the
 * logger at 0); equal priorities release in reverse registration order. Each closer runs even
 * if another throws; failures are returned, never thrown, so shutdown always completes.
 */
final class ResourceRegistry
{
    /** @var array<string, array{closer: callable(): void, priority: int, seq: int}> */
    private array $resources = [];

    private int $sequence = 0;

    private bool $releasing = false;

    /**
     * @param callable(): void $closer
     */
    public function register(string $name, callable $closer, int $priority = 0): void
    {
        if ($name === '') {
            throw new InvalidArgumentException('Resource name must be non-empty');
        }
        if (isset($this->resources[$name])) {
            throw new InvalidArgumentException("Resource '{$name}' is already registered");
        }
        $this->resources[$name] = ['closer' => $closer, 'priority' => $priority, 'seq' => $this->sequence++];
    }

    public function has(string $name): bool
    {
        return isset($this->resources[$name]);
    }

    /** Removes a resource without closing it (the owner released it already). */
    public function forget(string $name): void
    {
        unset($this->resources[$name]);
    }

    /** @return list<string> names in the order they would be released */
    public function names(): array
    {
        $ordered = $this->resources;
        uasort($ordered, static fn(array $a, array $b): int => [$b['priority'], $b['seq']] <=> [$a['priority'], $a['seq']]);

        return array_keys($ordered);
    }

    public function count(): int
    {
        return count($this->resources);
    }

    /**
     * Releases everything. Idempotent and re-entrancy safe.
     *
     * @return array<string, Throwable> failures keyed by resource name
     */
    public function releaseAll(): array
    {
        if ($this->releasing) {
            return [];
        }
        $this->releasing = true;
        $failures = [];

        try {
            foreach ($this->names() as $name) {
                $closer = $this->resources[$name]['closer'];
                unset($this->resources[$name]);
                try {
                    $closer();
                } catch (Throwable $e) {
                    $failures[$name] = $e;
                }
            }
        } finally {
            $this->releasing = false;
        }

        return $failures;
    }
}
