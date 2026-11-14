<?php

declare(strict_types=1);

namespace Omnitrix\Http\Middleware;

use Closure;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

use function array_reverse;

/**
 * Compiles a middleware list and a final handler into one closure, once. Dispatch is then a
 * chain of plain closure calls: no arrays walked, no container lookups per request.
 *
 * Middleware may be a {@see MiddlewareInterface} or a `callable(Request, Next): Response`.
 */
final class Pipeline
{
    /**
     * @param list<MiddlewareInterface|callable(Request, Next): Response> $middleware
     * @param callable(Request): Response                                $handler
     *
     * @return Closure(Request): Response
     */
    public static function compile(array $middleware, callable $handler): Closure
    {
        $next = $handler(...);
        foreach (array_reverse($middleware) as $layer) {
            $continuation = new Next($next);
            $next = $layer instanceof MiddlewareInterface
                ? static fn(Request $request): Response => $layer->process($request, $continuation)
                : static fn(Request $request): Response => $layer($request, $continuation);
        }

        return $next;
    }
}
