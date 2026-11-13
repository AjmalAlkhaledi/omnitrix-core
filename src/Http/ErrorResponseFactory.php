<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Status;
use Throwable;

use function array_slice;
use function explode;

/**
 * Converts any throwable into a JSON error {@see Response}.
 *
 * HTTP errors (`HttpException` and any {@see HttpErrorInterface}) expose their status, headers,
 * safe message and details. Everything else
 * becomes a 500 whose body carries only the generic phrase and the request id — unless debug
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

        $error = ['status' => Status::INTERNAL_SERVER_ERROR, 'message' => Status::phrase(Status::INTERNAL_SERVER_ERROR)];
        if ($this->debug) {
            $error['exception'] = $e::class;
            $error['detail'] = $e->getMessage();
            $error['file'] = $e->getFile() . ':' . $e->getLine();
            $error['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 15);
        }

        return Response::json(['error' => $error, 'request_id' => $requestId], Status::INTERNAL_SERVER_ERROR);
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}
