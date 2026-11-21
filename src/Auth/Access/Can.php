<?php

declare(strict_types=1);

namespace Omnitrix\Auth\Access;

use Closure;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use RuntimeException;

use function array_values;
use function is_array;
use function is_callable;
use function is_string;

/**
 * Checks an ability before the handler runs (after {@see \Omnitrix\Auth\Authenticate}):
 *
 * ```php
 * ->middleware(Can::ability('reports.view'))
 * ->middleware(Can::ability('update', fn (Request $r) => Post::findOrFail($r->routeInt('id'))))
 * ->middleware(Can::ability('create', Post::class))
 * ```
 */
final class Can implements MiddlewareInterface
{
    /** @param (Closure(Request): mixed)|string|null $arguments */
    private function __construct(private readonly string $ability, private readonly Closure|string|null $arguments) {}

    /** @param (callable(Request): mixed)|string|null $arguments a model class, or a callback returning the policy subject (or a list of arguments) */
    public static function ability(string $ability, callable|string|null $arguments = null): self
    {
        return new self($ability, is_callable($arguments) && !is_string($arguments) ? $arguments(...) : (is_string($arguments) ? $arguments : null));
    }

    public function process(Request $request, Next $next): Response
    {
        $container = Facade::container();
        $gate = $container !== null && $container->has(Gate::class) ? $container->get(Gate::class) : null;
        if (!$gate instanceof Gate) {
            throw new RuntimeException('No authorization gate in this worker');
        }
        $arguments = $this->arguments instanceof Closure ? ($this->arguments)($request) : $this->arguments;
        $arguments = $arguments === null ? [] : (is_array($arguments) ? array_values($arguments) : [$arguments]);
        $response = $gate->inspect($this->ability, ...$arguments);
        if ($response->denied()) {
            $message = $response->message ?? ($response->status === 404 ? 'Not Found' : 'This action is unauthorized');

            return Response::json(['error' => ['status' => $response->status, 'message' => $message], 'request_id' => $request->id()], $response->status);
        }

        return $next($request);
    }
}
