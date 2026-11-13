<?php

declare(strict_types=1);

namespace Omnitrix\Observability;

use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Psr\Log\LoggerInterface;

use function hrtime;
use function in_array;
use function round;

/**
 * One structured log line per request: method, route pattern, path, status, duration, request
 * id, client address. Query strings, headers and bodies are never logged (they may carry secrets).
 */
final class AccessLog implements MiddlewareInterface
{
    /** @param list<string> $skipPaths e.g. health checks */
    public function __construct(private readonly LoggerInterface $logger, private readonly array $skipPaths = []) {}

    public function process(Request $request, Next $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);
        if (!in_array($request->path(), $this->skipPaths, true)) {
            $this->logger->info('request', [
                'request_id' => $request->id(),
                'method' => $request->method(),
                'route' => $request->routePattern(),
                'path' => $request->path(),
                'status' => $response->getStatus(),
                'duration_ms' => round((hrtime(true) - $start) / 1e6, 3),
                'remote' => $request->remoteAddress(),
            ]);
        }

        return $response;
    }
}
