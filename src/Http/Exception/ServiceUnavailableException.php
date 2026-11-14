<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class ServiceUnavailableException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'Service temporarily unavailable', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::SERVICE_UNAVAILABLE, $message, $headers, $details, $previous);
    }
}
