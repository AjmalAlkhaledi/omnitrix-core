<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use Closure;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Security\BearerTokenAuth;
use Throwable;

/**
 * Rate limiting middleware. Keyed by authenticated identity when present, otherwise by client
 * address (configure a custom key for proxies). Adds `X-RateLimit-*` headers; exceeding the
 * limit answers 429 with `Retry-After`. If the limiter backend fails, requests are allowed
 * (fail-open) and the failure is reported — availability over strictness.
 */
final class RateLimit implements MiddlewareInterface
{
    /** @var Closure(Request): string */
    private readonly Closure $key;

    /** @var null|Closure(Throwable): void */
    private readonly ?Closure $onError;

    /**
     * @param null|callable(Request): string $key
     * @param null|callable(Throwable): void $onError
     */
    public function __construct(private readonly RateLimiterInterface $limiter, private readonly int $limit, private readonly int $windowSeconds = 60, ?callable $key = null, private readonly string $name = 'default', ?callable $onError = null)
    {
        $this->key = $key !== null ? $key(...) : static function (Request $request): string {
            $identity = BearerTokenAuth::identity($request);

            return $identity !== null ? 'id:' . $identity->id : 'ip:' . ($request->remoteAddress() ?? 'unknown');
        };
        $this->onError = $onError !== null ? $onError(...) : null;
    }

    public function process(Request $request, Next $next): Response
    {
        try {
            $result = $this->limiter->hit($this->name . ':' . ($this->key)($request), $this->limit, $this->windowSeconds);
        } catch (Throwable $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }

            return $next($request);
        }
        if (!$result->allowed) {
            return Response::json(['error' => ['status' => 429, 'message' => 'Too many requests'], 'request_id' => $request->id()], 429)
                ->header('Retry-After', (string) $result->retryAfter)
                ->header('X-RateLimit-Limit', (string) $result->limit)
                ->header('X-RateLimit-Remaining', '0');
        }

        return $next($request)
            ->header('X-RateLimit-Limit', (string) $result->limit)
            ->header('X-RateLimit-Remaining', (string) $result->remaining);
    }
}
