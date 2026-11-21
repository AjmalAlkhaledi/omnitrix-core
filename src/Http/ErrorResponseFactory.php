<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Database\Cache\CacheUnavailableException;
use Omnitrix\Core\Database\Exception\ConnectionException as DatabaseConnectionException;
use Omnitrix\Core\Database\Exception\PoolExhaustedException;
use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Status;
use Omnitrix\Core\Pool\PoolTimeoutException;
use Omnitrix\Core\Redis\Exception\ConnectionException as RedisConnectionException;
use Throwable;

use function array_slice;
use function explode;

/**
 * Converts any throwable into a JSON error {@see Response}.
 *
 * HTTP errors (`HttpException` and any {@see HttpErrorInterface}) expose their status, headers,
 * safe message and details. Everything else
 * becomes a 500 (503 when a database, Redis or pool is unavailable) whose body carries only the generic phrase and the request id — unless debug
 * mode is on, in which case class, message and a trimmed trace are included (never enable in
 * production).
 */
final class ErrorResponseFactory
{
    public function __construct(private readonly bool $debug = false) {}

    public function fromThrowable(Throwable $e, string $requestId): Response
    {
        if ($e instanceof HttpErrorInterface) {
            $error = ['status' => $e->status(), 'message' => $e->getMessage()] + $e->details();
            $response = Response::json(['error' => $error, 'request_id' => $requestId], $e->status());
            foreach ($e->headers() as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }

        // A dependency that is down or saturated (database or Redis connection, exhausted pool,
        // row cache unreachable before a write) is temporary and the request can be retried:
        // 503 lets clients and load balancers do so and separates outages from bugs.
        $status = self::unavailable($e) ? Status::SERVICE_UNAVAILABLE : Status::INTERNAL_SERVER_ERROR;
        $error = ['status' => $status, 'message' => Status::phrase($status)];
        if ($this->debug) {
            $error['exception'] = $e::class;
            $error['detail'] = $e->getMessage();
            $error['file'] = $e->getFile() . ':' . $e->getLine();
            $error['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);
        }

        $response = Response::json(['error' => $error, 'request_id' => $requestId], $status);

        return $status === Status::SERVICE_UNAVAILABLE ? $response->header('Retry-After', '1') : $response;
    }

    private static function unavailable(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof CacheUnavailableException
                || $cause instanceof DatabaseConnectionException
                || $cause instanceof PoolExhaustedException
                || $cause instanceof PoolTimeoutException
                || $cause instanceof RedisConnectionException) { // includes Redis timeouts
                return true;
            }
        }

        return false;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}
