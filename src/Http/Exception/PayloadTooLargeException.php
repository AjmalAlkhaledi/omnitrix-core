<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class PayloadTooLargeException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'Request body exceeds the allowed size', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::PAYLOAD_TOO_LARGE, $message, $headers, $details, $previous);
    }
}
