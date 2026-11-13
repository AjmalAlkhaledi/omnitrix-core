<?php

declare(strict_types=1);

namespace Omnitrix\Http\Middleware;

use Closure;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/**
 * The continuation handed to middleware. Calling it runs the rest of the pipeline.
 * One instance per pipeline layer is created at compile time, none per request.
 */
final class Next
{
    /** @param Closure(Request): Response $continuation */
    public function __construct(private readonly Closure $continuation) {}

    public function __invoke(Request $request): Response
    {
        return ($this->continuation)($request);
    }
}
