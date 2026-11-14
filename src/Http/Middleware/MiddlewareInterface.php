<?php

declare(strict_types=1);

namespace Omnitrix\Http\Middleware;

use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/**
 * Middleware wraps the rest of the pipeline. Call `$next($request)` to continue, or return a
 * Response without calling it to short-circuit.
 */
interface MiddlewareInterface
{
    public function process(Request $request, Next $next): Response;
}
