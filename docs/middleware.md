# Middleware

Middleware wraps route handlers. A middleware is either a class implementing
`Omnitrix\Http\Middleware\MiddlewareInterface` or a closure with the same shape:

```php
<?php

use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

final class TimingMiddleware implements MiddlewareInterface
{
    public function process(Request $request, Next $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);               // continue the pipeline

        return $response->header('X-Elapsed-Us', (string) (int) ((hrtime(true) - $start) / 1000));
    }
}

$requireToken = static function (Request $request, Next $next): Response {
    $token = $request->bearerToken();
    if ($token === null) {
        return Response::json(['error' => ['status' => 401, 'message' => 'missing token']], 401)
            ->header('WWW-Authenticate', 'Bearer');   // short-circuit: handler never runs
    }
    $request->setAttribute('auth.user', $token);     // visible to inner middleware and the handler

    return $next($request);
};
```

## Scopes and order

| Scope | How | Runs |
|---|---|---|
| Global | `$app->middleware($a, $b)` | for every matched route, in registration order |
| Group | `$app->group('/api', fn, [$m])` | for routes in the group (outer groups first) |
| Route | `$app->get(...)->middleware($m)` | for that route only |

The effective order is global → group (outermost to innermost) → route → handler. On the way
back the innermost response transformations happen first.

The pipeline for each route is compiled once per worker into nested closures (`Pipeline`),
with one `Next` continuation object per layer created at compile time; dispatching a request
allocates nothing for the pipeline itself.

## Errors inside the pipeline

Exceptions thrown by the handler are converted to a `Response` at the handler boundary, so
every middleware still receives a response (status, headers) and can log or decorate it.
`HttpException`s keep their status, headers and safe message; other throwables become a
generic `500` (details only with `debug => true`) and are reported to the exception handler.
Exceptions thrown by middleware itself, and routing failures (404/405), are converted by the
kernel above the pipeline.

Middleware that needs to react to failures inspects `$response->getStatus()`; it must not rely
on catching exceptions from `$next()`.

## Built-in production middleware

```php
<?php

use Omnitrix\Observability\AccessLog;
use Omnitrix\RateLimit\{RateLimit, RedisRateLimiter, TableRateLimiter};
use Omnitrix\Security\{Authorize, BearerTokenAuth, Identity, SecurityHeaders};

$auth = new BearerTokenAuth(fn (string $token): ?Identity => $tokens->find($token)); // any coroutine-friendly lookup
$limiter = new TableRateLimiter();            // per server, shared memory; create before run()
// $limiter = new RedisRateLimiter();         // across servers; uses the `redis` section

$app->middleware(new SecurityHeaders(), new AccessLog($app->logger(), ['/health/live']));
$app->group('/api', function ($r) use ($limiter) {
    $r->get('/orders', OrdersController::class . '::index')->middleware(Authorize::ability('orders.read'));
    $r->post('/orders', OrdersController::class . '::store')->middleware(new RateLimit($limiter, 30, 60));
}, [$auth]);
```

| Middleware | Behaviour |
|---|---|
| `BearerTokenAuth` | resolves `Authorization: Bearer` via your callback; stores the `Identity` on the request (request-scoped); 401 + `WWW-Authenticate` otherwise; `optional: true` lets anonymous requests through |
| `Authorize` | `Authorize::ability('x')` or a custom `fn (Identity, Request): bool`; 401 without identity, 403 when denied |
| `RateLimit` | fixed window per identity (or client address, or a custom key); `X-RateLimit-*` headers; 429 + `Retry-After`; fails open (and reports) when the backend is down |
| `SecurityHeaders` | `nosniff`, `DENY` framing, `no-referrer`, restrictive CSP, CORP, `no-store`, optional HSTS; never overrides headers the handler set |
| `AccessLog` | one JSON line per request (method, route pattern, path, status, duration, request id, address); no query strings, headers or bodies |

Validation lives on the request: `$request->validate(['email' => 'required|email'])` returns
only the validated fields or answers 422 with per-field messages (see [configuration.md](configuration.md#validation-rules)
for the rule list).

Identities are request-scoped: concurrent requests with distinct tokens on the same worker
each see only their own identity.
