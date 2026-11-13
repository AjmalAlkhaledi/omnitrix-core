<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Context;

use InvalidArgumentException;
use Omnitrix\Core\Support\Id;
use Throwable;

use function array_key_exists;
use function array_reverse;
use function hrtime;
use function microtime;

/**
 * Per-request state container.
 *
 * One instance exists per handled request, bound to the request coroutine through
 * {@see ContextStorage}. Child coroutines spawned while handling the request see the same
 * instance. It carries an id, typed attributes, a deadline and deferred callbacks that run
 * exactly once when the request finishes (successfully or not), in LIFO order.
 *
 * Never store a RequestContext in a static property or a worker-scoped service: that is the
 * request-leak pattern this class exists to prevent.
 */
final class RequestContext
{
    private readonly int $startedAtNs;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var list<callable(): void> */
    private array $deferred = [];

    private bool $disposed = false;

    private ?float $deadlineAt = null;

    public function __construct(public readonly string $id)
    {
        if ($this->id === '') {
            throw new InvalidArgumentException('RequestContext id must be non-empty; use RequestContext::create()');
        }
        $this->startedAtNs = hrtime(true);
    }

    public static function create(): self
    {
        return new self(Id::generate());
    }

    /** Seconds elapsed since the context was created. */
    public function elapsed(): float
    {
        return (hrtime(true) - $this->startedAtNs) / 1e9;
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertLive();
        $this->attributes[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->attributes);
    }

    public function remove(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->attributes;
    }

    /**
     * Absolute deadline as a unix timestamp (float seconds). Consumers (pools, clients) read
     * {@see remainingTime()} to bound their waits.
     */
    public function setDeadline(float $unixTimestamp): void
    {
        $this->assertLive();
        $this->deadlineAt = $unixTimestamp;
    }

    public function deadline(): ?float
    {
        return $this->deadlineAt;
    }

    /** Seconds until the deadline (may be negative once expired), or null when no deadline. */
    public function remainingTime(): ?float
    {
        return $this->deadlineAt === null ? null : $this->deadlineAt - microtime(true);
    }

    public function isExpired(): bool
    {
        $remaining = $this->remainingTime();

        return $remaining !== null && $remaining <= 0.0;
    }

    /**
     * Registers a callback executed when the request ends. Callbacks run in reverse
     * registration order; each runs even if an earlier one throws.
     *
     * @param callable(): void $callback
     */
    public function defer(callable $callback): void
    {
        $this->assertLive();
        $this->deferred[] = $callback;
    }

    public function isDisposed(): bool
    {
        return $this->disposed;
    }

    /**
     * Runs deferred callbacks (LIFO) and clears attributes. Idempotent.
     *
     * @return list<Throwable> failures raised by deferred callbacks
     */
    public function dispose(): array
    {
        if ($this->disposed) {
            return [];
        }
        $this->disposed = true;

        $failures = [];
        foreach (array_reverse($this->deferred) as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $failures[] = $e;
            }
        }
        $this->deferred = [];
        $this->attributes = [];

        return $failures;
    }

    private function assertLive(): void
    {
        if ($this->disposed) {
            throw new ContextNotAvailableException("RequestContext {$this->id} is already disposed");
        }
    }
}
