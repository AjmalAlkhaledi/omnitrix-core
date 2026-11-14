<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class NotFoundException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'Resource not found', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::NOT_FOUND, $message, $headers, $details, $previous);
    }
}
