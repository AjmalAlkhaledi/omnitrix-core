<?php

declare(strict_types=1);

namespace Omnitrix\Security;

use Closure;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/**
 * Authenticates `Authorization: Bearer <token>` through an application-supplied resolver
 * (database lookup, JWT verification, introspection call — any coroutine-friendly code).
 * The identity is stored on the request object (request-scoped; never in shared state).
 * Missing/invalid tokens → 401 with `WWW-Authenticate: Bearer`.
 */
final class BearerTokenAuth implements MiddlewareInterface
{
    public const string ATTRIBUTE = 'auth.identity';

    /** @var Closure(string, Request): ?Identity */
    private readonly Closure $resolver;

    /** @param callable(string, Request): ?Identity $resolver */
    public function __construct(callable $resolver, private readonly bool $optional = false)
    {
        $this->resolver = $resolver(...);
    }

    public function process(Request $request, Next $next): Response
    {
        $token = $request->bearerToken();
        $identity = $token === null ? null : ($this->resolver)($token, $request);
        if ($identity === null && !$this->optional) {
            return Response::json(['error' => ['status' => 401, 'message' => $token === null ? 'Authentication required' : 'Invalid token'], 'request_id' => $request->id()], 401)
                ->header('WWW-Authenticate', 'Bearer' . ($token === null ? '' : ' error="invalid_token"'));
        }
        if ($identity !== null) {
            $request->setAttribute(self::ATTRIBUTE, $identity);
            $request->context()->set(self::ATTRIBUTE, $identity);
        }

        return $next($request);
    }

    public static function identity(Request $request): ?Identity
    {
        $identity = $request->attribute(self::ATTRIBUTE);

        return $identity instanceof Identity ? $identity : null;
    }
}
