<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class BadRequestException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'The request could not be understood', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::BAD_REQUEST, $message, $headers, $details, $previous);
    }
}
