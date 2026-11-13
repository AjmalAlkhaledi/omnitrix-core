<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use LogicException;
use Psr\Container\ContainerInterface;

use function get_debug_type;

/**
 * Static accessors over worker-scoped services.
 *
 * The container is set once per worker process in `WorkerStart` (each OpenSwoole worker is a
 * separate process with its own copy). Facades never hold request state: they only resolve a
 * worker-scoped service, which in turn reads request state from the coroutine context.
 */
abstract class Facade
{
    private static ?ContainerInterface $container = null;

    public static function setContainer(?ContainerInterface $container): void
    {
        self::$container = $container;
    }

    public static function hasContainer(): bool
    {
        return self::$container !== null;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    protected static function resolve(string $class): object
    {
        $container = self::$container ?? throw new LogicException("No container bound for facades; is the worker started? (" . static::class . ')');
        if (!$container->has($class)) {
            throw new LogicException("Service {$class} is not registered; is the module configured? (" . static::class . ')');
        }
        $service = $container->get($class);
        if (!$service instanceof $class) {
            throw new LogicException("Container returned " . get_debug_type($service) . " for {$class}");
        }

        return $service;
    }
}
