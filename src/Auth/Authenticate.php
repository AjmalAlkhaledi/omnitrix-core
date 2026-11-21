<?php

declare(strict_types=1);

namespace Omnitrix\Auth;

use Omnitrix\Facades\Facade;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Security\BearerTokenAuth;
use Omnitrix\Security\Identity;
use RuntimeException;

use function array_filter;
use function array_values;
use function is_array;

/**
 * Requires an authenticated user: `->middleware(Authenticate::using('api'))` (several guards: the
 * first that authenticates wins; none: the default guard). Unauthenticated requests get 401.
 * The user is also exposed as an {@see Identity} (with the token's abilities), so
 * {@see \Omnitrix\Security\Authorize} and rate limiting key on it.
 */
final class Authenticate implements MiddlewareInterface
{
    public const string GUARD_ATTRIBUTE = 'auth.guard';

    /** @param list<string> $guards */
    private function __construct(private readonly array $guards) {}

    public static function using(string ...$guards): self
    {
        return new self(array_values($guards));
    }

    public function process(Request $request, Next $next): Response
    {
        $container = Facade::container();
        $auth = $container !== null && $container->has(AuthManager::class) ? $container->get(AuthManager::class) : null;
        if (!$auth instanceof AuthManager) {
            throw new RuntimeException('Authentication is not configured (add an auth section)');
        }
        foreach ($this->guards === [] ? [$auth->defaultGuard()] : $this->guards as $name) {
            $guard = $auth->guard($name);
            $user = $guard->user();
            if ($user === null) {
                continue;
            }
            $abilities = ['*'];
            if ($guard instanceof TokenGuard) {
                $token = $guard->token();
                $abilities = is_array($token?->abilities) ? array_values(array_filter($token->abilities, 'is_string')) : [];
            }
            $identity = new Identity((string) $user->getAuthIdentifier(), $abilities, ['guard' => $name]);
            $request->setAttribute(BearerTokenAuth::ATTRIBUTE, $identity);
            $request->context()->set(BearerTokenAuth::ATTRIBUTE, $identity);
            $request->setAttribute(self::GUARD_ATTRIBUTE, $name);

            return $next($request);
        }

        return Response::json(['error' => ['status' => 401, 'message' => 'Unauthenticated'], 'request_id' => $request->id()], 401)
            ->header('WWW-Authenticate', 'Bearer');
    }
}
