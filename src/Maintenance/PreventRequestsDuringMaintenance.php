<?php

declare(strict_types=1);

namespace Omnitrix\Maintenance;

use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Http\Middleware\Next;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

use function fnmatch;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/**
 * Answers `503 Service Unavailable` while the application is down, except for the `except` paths
 * (health checks and metrics by default), the IPs given to `down --allow`, and requests with
 * the `X-Maintenance-Bypass` header set to the `down --secret`.
 */
final readonly class PreventRequestsDuringMaintenance implements MiddlewareInterface
{
    public const string BYPASS_HEADER = 'X-Maintenance-Bypass';

    public function __construct(private MaintenanceMode $mode) {}

    public function process(Request $request, Next $next): Response
    {
        return $this->check($request) ?? $next($request);
    }

    /** The 503 response while down, null when the request may go on. */
    public function check(Request $request): ?Response
    {
        $state = $this->mode->state();
        if ($state === null || $this->excepted($request, $state)) {
            return null;
        }
        $message = $state['message'] ?? null;
        $response = Response::json(['error' => 'Service Unavailable', 'message' => is_string($message) && $message !== '' ? $message : 'The service is down for maintenance.'], 503);
        $retry = $state['retry'] ?? null;
        if (is_int($retry) && $retry > 0) {
            $response->header('Retry-After', (string) $retry);
        }

        return $response->header('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $state */
    private function excepted(Request $request, array $state): bool
    {
        $path = $request->path();
        foreach ($this->mode->except as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        $allow = $state['allow'] ?? [];
        if (is_array($allow) && in_array($request->remoteAddress(), $allow, true)) {
            return true;
        }
        $secret = $request->header(self::BYPASS_HEADER);

        return is_string($secret) && $this->mode->bypasses($secret);
    }
}
