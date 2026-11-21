<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Runtime\Context\ContextStorage;

use function is_array;

/**
 * Cookies to send with the current request's response, from code that has no response at hand
 * (a guard logging a user in). The kernel and {@see \Omnitrix\Session\StartSession} attach them.
 */
final class CookieJar
{
    private const string KEY = 'http.queued_cookies';

    private function __construct() {}

    public static function queue(Cookie $cookie): void
    {
        $context = ContextStorage::current();
        if ($context === null) {
            return;
        }
        $queued = $context->get(self::KEY, []);
        $queued = is_array($queued) ? $queued : [];
        $queued[$cookie->name] = $cookie;
        $context->set(self::KEY, $queued);
    }

    /** Adds the queued cookies to `$response` and forgets them. */
    public static function flush(Response $response): Response
    {
        $context = ContextStorage::current();
        $queued = $context?->get(self::KEY, []);
        if (!is_array($queued) || $queued === [] || $context === null) {
            return $response;
        }
        $context->set(self::KEY, []);
        foreach ($queued as $cookie) {
            if ($cookie instanceof Cookie) {
                $response->cookie($cookie);
            }
        }

        return $response;
    }
}
