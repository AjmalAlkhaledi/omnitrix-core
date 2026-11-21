<?php

declare(strict_types=1);

namespace Omnitrix\Security;

use Omnitrix\Http\Cookie;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Session\StartSession;
use Omnitrix\Session\Store;
use RuntimeException;

use function fnmatch;
use function hash_equals;
use function in_array;
use function is_string;
use function ltrim;

/**
 * CSRF protection for session-based (browser) clients; place it after {@see StartSession}.
 * Requests that change state (POST, PUT, PATCH, DELETE) must send the session's token in the
 * `_token` field or the `X-CSRF-TOKEN` / `X-XSRF-TOKEN` header; otherwise they are answered
 * 419. Every response refreshes an `XSRF-TOKEN` cookie that JavaScript can read and send back.
 *
 * Requests with a bearer token are not checked by default: browsers never add an
 * `Authorization` header on their own, so such requests cannot be forged cross-site.
 */
final class VerifyCsrfToken implements MiddlewareInterface
{
    /** @param list<string> $except paths (wildcards: `webhooks/*`) not checked */
    public function __construct(private readonly array $except = [], private readonly bool $skipBearer = true, private readonly bool $addXsrfCookie = true) {}

    public function process(Request $request, Next $next): Response
    {
        $session = $request->attribute(StartSession::ATTRIBUTE);
        if (!$session instanceof Store) {
            throw new RuntimeException('VerifyCsrfToken needs StartSession before it');
        }
        if (!$this->isReading($request) && !$this->isExcepted($request) && !($this->skipBearer && $request->bearerToken() !== null) && !$this->tokensMatch($request, $session)) {
            return Response::json(['error' => ['status' => 419, 'message' => 'CSRF token mismatch'], 'request_id' => $request->id()], 419);
        }
        $response = $next($request);
        if ($this->addXsrfCookie) {
            $response->cookie(new Cookie('XSRF-TOKEN', $session->token(), 0, '/', '', false, false, 'Lax'));
        }

        return $response;
    }

    private function isReading(Request $request): bool
    {
        return in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function isExcepted(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        foreach ($this->except as $pattern) {
            if (fnmatch(ltrim($pattern, '/'), $path)) {
                return true;
            }
        }

        return false;
    }

    private function tokensMatch(Request $request, Store $session): bool
    {
        $sent = $request->input('_token') ?? $request->header('X-CSRF-TOKEN') ?? $request->header('X-XSRF-TOKEN');

        return is_string($sent) && $sent !== '' && hash_equals($session->token(), $sent);
    }
}
