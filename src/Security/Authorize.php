<?php

declare(strict_types=1);

namespace Omnitrix\Security;

use Closure;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/**
 * Authorization hook: `Authorize::ability('orders.write')` or a custom policy
 * `new Authorize(fn (Identity $who, Request $r) => $who->id === $r->route('userId'))`.
 * Runs after {@see BearerTokenAuth}; unauthenticated → 401, denied → 403.
 */
final class Authorize implements MiddlewareInterface
{
    /** @var Closure(Identity, Request): bool */
    private readonly Closure $policy;

    /** @param callable(Identity, Request): bool $policy */
    public function __construct(callable $policy)
    {
        $this->policy = $policy(...);
    }

    public static function ability(string $ability): self
    {
        return new self(static fn(Identity $identity): bool => $identity->can($ability));
    }

    public function process(Request $request, Next $next): Response
    {
        $identity = BearerTokenAuth::identity($request);
        if ($identity === null) {
            return Response::json(['error' => ['status' => 401, 'message' => 'Authentication required'], 'request_id' => $request->id()], 401)->header('WWW-Authenticate', 'Bearer');
        }
        if (!($this->policy)($identity, $request)) {
            return Response::json(['error' => ['status' => 403, 'message' => 'Forbidden'], 'request_id' => $request->id()], 403);
        }

        return $next($request);
    }
}
