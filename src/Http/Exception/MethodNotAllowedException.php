<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class MethodNotAllowedException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'Method not allowed', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::METHOD_NOT_ALLOWED, $message, $headers, $details, $previous);
    }
}
