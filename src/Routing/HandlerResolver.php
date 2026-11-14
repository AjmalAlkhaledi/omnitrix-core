<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

use Closure;
use InvalidArgumentException;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Psr\Container\ContainerInterface;

use function class_exists;
use function count;
use function explode;
use function get_debug_type;
use function is_array;
use function is_callable;
use function is_object;
use function is_string;
use function method_exists;
use function str_contains;

/**
 * Turns a route handler declaration into a `Closure(Request): Response`-like callable, once
 * per route (at compile time, never per request).
 *
 * Accepted forms: closures and other callables; `[ClassName::class, 'method']`;
 * `'ClassName::method'`; an invokable class name. Class instances come from the container
 * when it has an entry for the class, otherwise from `new ClassName()`.
 */
final class HandlerResolver
{
    public function __construct(private readonly ?ContainerInterface $container = null) {}

    /** @return Closure(Request): mixed */
    public function resolve(mixed $handler): Closure
    {
        if ($handler instanceof Closure) {
            return $handler;
        }
        if (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
            $handler = [$class, $method];
        }
        if (is_array($handler) && count($handler) === 2 && is_string($handler[1] ?? null)) {
            $target = $handler[0] ?? null;
            $method = $handler[1];
            $instance = is_object($target) ? $target : $this->instantiate($target);
            $callable = [$instance, $method];
            if (!method_exists($instance, $method) || !is_callable($callable)) {
                throw new InvalidArgumentException('Route handler method ' . $instance::class . "::{$method}() does not exist or is not public");
            }

            return Closure::fromCallable($callable);
        }
        if (is_string($handler) && class_exists($handler)) {
            $instance = $this->instantiate($handler);
            if (!is_callable($instance)) {
                throw new InvalidArgumentException("Route handler class {$handler} is not invokable");
            }

            return Closure::fromCallable($instance);
        }
        if (is_callable($handler)) {
            return Closure::fromCallable($handler);
        }

        throw new InvalidArgumentException('Unsupported route handler of type ' . get_debug_type($handler));
    }

    private function instantiate(mixed $class): object
    {
        if (!is_string($class) || !class_exists($class)) {
            throw new InvalidArgumentException('Route handler class ' . (is_string($class) ? $class : get_debug_type($class)) . ' does not exist');
        }
        if ($this->container !== null && $this->container->has($class)) {
            $instance = $this->container->get($class);
            if (!$instance instanceof $class) {
                throw new InvalidArgumentException("Container entry '{$class}' is not an instance of {$class}");
            }

            return $instance;
        }

        return new $class();
    }
}
