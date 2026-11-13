<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

use Closure;
use InvalidArgumentException;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

use function preg_match;

/**
 * A registered route: methods, path pattern, handler, middleware and an optional name.
 * Mutable until the router compiles; afterwards only the compiled pipeline is used.
 */
final class Route
{
    /** @var list<MiddlewareInterface|callable(Request, Next): Response> */
    private array $middleware = [];

    private ?string $name = null;

    /** @var null|Closure(Request): Response set by the router at first dispatch */
    private ?Closure $pipeline = null;

    /**
     * @param list<string> $methods upper-case
     * @param mixed        $handler closure, callable, [class, method], "Class::method" or invokable class name
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly mixed $handler,
    ) {
        if ($pattern === '' || $pattern[0] !== '/') {
            throw new InvalidArgumentException("Route pattern must start with '/', got '{$pattern}'");
        }
    }

    /** @param MiddlewareInterface|callable(Request, Next): Response ...$middleware */
    public function middleware(MiddlewareInterface|callable ...$middleware): self
    {
        foreach ($middleware as $layer) {
            $this->middleware[] = $layer;
        }

        return $this;
    }

    public function name(string $name): self
    {
        if (preg_match('/^[A-Za-z0-9_.:-]+$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid route name '{$name}'");
        }
        $this->name = $name;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    /** @return list<MiddlewareInterface|callable(Request, Next): Response> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /** @param Closure(Request): Response $pipeline */
    public function setPipeline(Closure $pipeline): void
    {
        $this->pipeline = $pipeline;
    }

    /** @return null|Closure(Request): Response */
    public function getPipeline(): ?Closure
    {
        return $this->pipeline;
    }
}
