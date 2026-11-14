<?php

declare(strict_types=1);

namespace Omnitrix\Security;

use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

/** Adds conservative security headers suited to JSON APIs (only where the handler did not set them). */
final class SecurityHeaders implements MiddlewareInterface
{
    /** @param array<string, string> $headers overrides / additions */
    public function __construct(private readonly array $headers = [], private readonly bool $hsts = false) {}

    public function process(Request $request, Next $next): Response
    {
        $response = $next($request);
        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Cache-Control' => 'no-store',
        ];
        if ($this->hsts) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }
        foreach ($this->headers + $defaults as $name => $value) {
            if ($response->getHeader($name) === null) {
                $response->header($name, $value);
            }
        }

        return $response;
    }
}
