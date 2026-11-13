<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

use Closure;
use FastRoute\DataGenerator\GroupCountBased as GroupCountBasedGenerator;
use FastRoute\Dispatcher;
use FastRoute\Dispatcher\GroupCountBased as GroupCountBasedDispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use InvalidArgumentException;
use LogicException;
use Omnitrix\Http\Exception\MethodNotAllowedException;
use Omnitrix\Http\Exception\NotFoundException;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

use function array_filter;
use function array_map;
use function array_pop;
use function array_values;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function rawurldecode;
use function rtrim;
use function sort;
use function str_contains;
use function strtoupper;

/**
 * Route registration and dispatch on top of FastRoute's GroupCountBased engine.
 *
 * Registration happens in the master process before the server starts; {@see compile()} runs
 * once per worker and produces the dispatcher data (static routes hashed, dynamic routes in
 * grouped regexes). {@see dispatch()} returns a {@see RouteMatch} or throws the HTTP
 * exceptions for 404/405. HEAD falls back to GET routes.
 *
 * Patterns use FastRoute syntax: `/users/{id}`, `/users/{id:\d+}`, `/files[/{path:.+}]`.
 */
final class Router
{
    public const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    /** @var list<Route> */
    private array $routes = [];

    /** @var list<string> prefixes of the groups currently being defined */
    private array $prefixStack = [];

    /** @var list<list<MiddlewareInterface|callable(Request, Next): Response>> */
    private array $middlewareStack = [];

    private ?Dispatcher $dispatcher = null;

    /** @var array<int, Route> FastRoute handler id => Route */
    private array $byId = [];

    public function get(string $pattern, mixed $handler): Route
    {
        return $this->map(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): Route
    {
        return $this->map(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): Route
    {
        return $this->map(['PUT'], $pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->map(['PATCH'], $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->map(['DELETE'], $pattern, $handler);
    }

    public function options(string $pattern, mixed $handler): Route
    {
        return $this->map(['OPTIONS'], $pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): Route
    {
        return $this->map(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $pattern, $handler);
    }

    /** @param list<string> $methods */
    public function map(array $methods, string $pattern, mixed $handler): Route
    {
        if ($this->dispatcher !== null) {
            throw new LogicException('Routes cannot be added after the router was compiled');
        }
        $upper = [];
        foreach ($methods as $method) {
            $method = strtoupper($method);
            if (!in_array($method, self::METHODS, true)) {
                throw new InvalidArgumentException("Unsupported HTTP method '{$method}'");
            }
            $upper[] = $method;
        }
        if ($upper === []) {
            throw new InvalidArgumentException('A route needs at least one method');
        }

        $prefix = implode('', $this->prefixStack);
        $full = $prefix . ($pattern === '/' && $prefix !== '' ? '' : $pattern);
        $route = new Route($upper, $full === '' ? '/' : $full, $handler);
        foreach ($this->middlewareStack as $layer) {
            $route->middleware(...$layer);
        }
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Defines routes under a common prefix and middleware set.
     *
     * @param callable(Router): void                                                              $define
     * @param list<MiddlewareInterface|callable(Request, Next): Response>  $middleware
     */
    public function group(string $prefix, callable $define, array $middleware = []): void
    {
        if ($prefix !== '' && ($prefix[0] !== '/' || rtrim($prefix, '/') !== $prefix)) {
            throw new InvalidArgumentException("Group prefix must start with '/' and not end with '/', got '{$prefix}'");
        }
        $this->prefixStack[] = $prefix;
        $this->middlewareStack[] = $middleware;
        try {
            $define($this);
        } finally {
            array_pop($this->prefixStack);
            array_pop($this->middlewareStack);
        }
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function isCompiled(): bool
    {
        return $this->dispatcher !== null;
    }

    /** Builds the dispatcher data. Idempotent. */
    public function compile(): void
    {
        if ($this->dispatcher !== null) {
            return;
        }
        $collector = new RouteCollector(new Std(), new GroupCountBasedGenerator());
        foreach ($this->routes as $id => $route) {
            $this->byId[$id] = $route;
            $collector->addRoute($route->methods, $route->pattern, $id);
        }
        $this->dispatcher = new GroupCountBasedDispatcher($collector->getData());
    }

    /**
     * @throws NotFoundException
     * @throws MethodNotAllowedException with an Allow header
     * @throws LogicException when called before {@see compile()}
     */
    public function dispatch(string $method, string $path): RouteMatch
    {
        $dispatcher = $this->dispatcher ?? throw new LogicException('Router::compile() must run before dispatch()');
        $method = strtoupper($method);
        // Decoding is only needed when the path contains escapes; most paths do not.
        $decoded = str_contains($path, '%') ? rawurldecode($path) : $path;
        if ($decoded === '') {
            $decoded = '/';
        }

        $result = $dispatcher->dispatch($method, $decoded);
        if ($result[0] === Dispatcher::NOT_FOUND && $method === 'HEAD') {
            $result = $dispatcher->dispatch('GET', $decoded);
        }

        switch ($result[0]) {
            case Dispatcher::FOUND:
                $id = $result[1];
                $route = is_int($id) ? ($this->byId[$id] ?? null) : null;
                if ($route === null) {
                    throw new LogicException('Dispatcher returned an unknown route id');
                }

                return new RouteMatch($route, self::params(is_array($result[2]) ? $result[2] : []));

            case Dispatcher::METHOD_NOT_ALLOWED:
                $allowed = is_array($result[1]) ? array_map(static fn(mixed $m): string => is_string($m) ? $m : '', $result[1]) : [];
                if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
                    $allowed[] = 'HEAD';
                }
                sort($allowed);

                throw new MethodNotAllowedException(headers: ['Allow' => implode(', ', $allowed)], details: ['allowed' => $allowed]);

            default:
                throw new NotFoundException();
        }
    }

    /**
     * @param array<mixed> $vars
     * @return array<string, string>
     */
    private static function params(array $vars): array
    {
        $params = [];
        foreach ($vars as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /** @return list<Route> routes that respond to `$method` (used for OPTIONS / diagnostics) */
    public function routesFor(string $method): array
    {
        $method = strtoupper($method);

        return array_values(array_filter($this->routes, static fn(Route $r): bool => in_array($method, $r->methods, true)));
    }

    /**
     * Returns the route's compiled pipeline, building it with `$factory` on first use.
     *
     * @param Closure(Route): (Closure(Request): Response) $factory
     *
     * @return Closure(Request): Response
     */
    public function pipelineFor(Route $route, Closure $factory): Closure
    {
        $pipeline = $route->getPipeline();
        if ($pipeline === null) {
            $pipeline = $factory($route);
            $route->setPipeline($pipeline);
        }

        return $pipeline;
    }
}
