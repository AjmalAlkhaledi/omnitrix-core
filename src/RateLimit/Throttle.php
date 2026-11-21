<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Security\BearerTokenAuth;
use Throwable;

use function min;

/**
 * Applies a named limiter (`$app->rateLimiter('api', …)`) to routes:
 * `->middleware(Throttle::using('api'))`. Every limit the limiter returns must pass; the
 * response carries `X-RateLimit-*` headers of the tightest limit. A failing backend lets
 * requests through (fail-open), like {@see RateLimit}.
 */
final class Throttle implements MiddlewareInterface
{
    private function __construct(private readonly string $name) {}

    public static function using(string $name): self
    {
        return new self($name);
    }

    public function process(Request $request, Next $next): Response
    {
        $limiter = Facade::container()?->get(RateLimiter::class);
        if (!$limiter instanceof RateLimiter) {
            return $next($request);
        }
        $tightest = null;
        foreach ($limiter->limitsFor($this->name, $request) as $i => $limit) {
            $key = $this->name . ':' . $i . ':' . ($limit->key !== '' ? $limit->key : self::defaultKey($request));
            try {
                $result = $limiter->hit($key, $limit->maxAttempts, $limit->decaySeconds);
            } catch (Throwable) {
                continue; // fail open
            }
            if (!$result->allowed) {
                return $limit->response !== null
                    ? ($limit->response)($request, $result)
                    : Response::json(['error' => ['status' => 429, 'message' => 'Too many requests'], 'request_id' => $request->id()], 429)
                        ->header('Retry-After', (string) $result->retryAfter)
                        ->header('X-RateLimit-Limit', (string) $result->limit)
                        ->header('X-RateLimit-Remaining', '0');
            }
            if ($tightest === null || $result->remaining < $tightest->remaining) {
                $tightest = $result;
            }
        }
        $response = $next($request);

        return $tightest === null ? $response : $response
            ->header('X-RateLimit-Limit', (string) $tightest->limit)
            ->header('X-RateLimit-Remaining', (string) min($tightest->remaining, $tightest->limit));
    }

    private static function defaultKey(Request $request): string
    {
        $identity = BearerTokenAuth::identity($request);

        return $identity !== null ? 'id:' . $identity->id : 'ip:' . ($request->remoteAddress() ?? 'unknown');
    }
}
