<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use InvalidArgumentException;
use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Status;
use RuntimeException;
use Throwable;

/**
 * An exception that maps directly to an HTTP response. The message is considered safe to show
 * to clients; internal details belong in `$previous` or the log context, never in the message.
 */
class HttpException extends RuntimeException implements HttpErrorInterface
{
    /**
     * @param array<string, string> $headers extra response headers (e.g. Retry-After, Allow)
     * @param array<string, mixed>  $details safe, structured extra data for the JSON body
     */
    public function __construct(
        private readonly int $status,
        string $message = '',
        private readonly array $headers = [],
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        if (!Status::isError($status) || !Status::isValid($status)) {
            throw new InvalidArgumentException("HttpException requires an error status (4xx/5xx), got {$status}");
        }
        parent::__construct($message === '' ? Status::phrase($status) : $message, $status, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
