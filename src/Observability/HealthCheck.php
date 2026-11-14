<?php

declare(strict_types=1);

namespace Omnitrix\Observability;

use Closure;
use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Http\Response;
use Throwable;

use function hrtime;
use function round;

/**
 * Liveness and readiness. Readiness runs every registered check concurrently with a timeout;
 * any failure → 503. Check errors are summarised without internal details.
 */
final class HealthCheck
{
    /** @var array<string, Closure(): bool> */
    private array $checks = [];

    public function __construct(private readonly float $timeout = 2.0) {}

    /** @param callable(): bool $check returns true when healthy; may throw */
    public function add(string $name, callable $check): self
    {
        $this->checks[$name] = $check(...);

        return $this;
    }

    public function live(): Response
    {
        return Response::json(['status' => 'ok']);
    }

    public function ready(): Response
    {
        $started = hrtime(true);
        $tasks = [];
        foreach ($this->checks as $name => $check) {
            $tasks[$name] = static fn(): bool => $check();
        }
        $outcomes = $tasks === [] ? [] : Async::allSettled($tasks, $this->timeout);
        $results = [];
        $healthy = true;
        foreach ($outcomes as $name => $outcome) {
            $ok = $outcome->fulfilled && $outcome->value === true;
            $healthy = $healthy && $ok;
            $results[(string) $name] = $ok ? 'ok' : ($outcome->cancelled ? 'timeout' : ($outcome->reason instanceof Throwable ? 'error: ' . $outcome->reason::class : 'failed'));
        }

        return Response::json(['status' => $healthy ? 'ok' : 'unavailable', 'checks' => $results, 'duration_ms' => round((hrtime(true) - $started) / 1e6, 2)], $healthy ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
