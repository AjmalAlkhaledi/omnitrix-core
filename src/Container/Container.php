<?php

declare(strict_types=1);

namespace Omnitrix\Core\Container;

use Psr\Container\ContainerInterface;
use Throwable;

use function array_key_exists;
use function array_keys;
use function get_debug_type;
use function implode;

/**
 * Small explicit PSR-11 container (no autowiring, no reflection).
 *
 * Scope is defined by *where* the container lives: the framework builds one container per
 * worker process in `onWorkerStart`, so every `singleton` is worker-scoped by construction.
 * Request-scoped objects never go here; they live in the RequestContext.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, callable(Container): mixed> */
    private array $factories = [];

    /** @var array<string, true> */
    private array $shared = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /** @var array<string, true> */
    private array $resolving = [];

    /** Registers a factory that produces a new instance on every `get()`. */
    public function bind(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->shared[$id], $this->instances[$id]);
    }

    /** Registers a factory whose first result is cached for the container's lifetime. */
    public function singleton(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
        unset($this->instances[$id]);
    }

    /** Registers an existing instance. */
    public function instance(string $id, mixed $instance): void
    {
        $this->instances[$id] = $instance;
        $this->shared[$id] = true;
        unset($this->factories[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->instances);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id] ?? throw new NotFoundException("No entry registered for '{$id}'");

        if (isset($this->resolving[$id])) {
            throw new ContainerException("Circular dependency while resolving '{$id}' (chain: " . implode(' -> ', array_keys($this->resolving)) . ")");
        }

        $this->resolving[$id] = true;
        try {
            $value = $factory($this);
        } catch (NotFoundException|ContainerException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ContainerException("Factory for '{$id}' failed: " . $e->getMessage(), 0, $e);
        } finally {
            unset($this->resolving[$id]);
        }

        if (isset($this->shared[$id])) {
            $this->instances[$id] = $value;
        }

        return $value;
    }

    /**
     * Typed accessor: resolves `$id` and asserts the instance is a `$class`.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function getTyped(string $id, string $class): object
    {
        $value = $this->get($id);
        if (!$value instanceof $class) {
            throw new ContainerException("Entry '{$id}' is " . get_debug_type($value) . ", expected {$class}");
        }

        return $value;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->factories + $this->instances);
    }
}
