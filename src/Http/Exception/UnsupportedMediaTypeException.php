<?php

declare(strict_types=1);

namespace Omnitrix\Http\Exception;

use Omnitrix\Core\Http\Status;
use Throwable;

final class UnsupportedMediaTypeException extends HttpException
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(string $message = 'Unsupported Content-Type', array $headers = [], array $details = [], ?Throwable $previous = null)
    {
        parent::__construct(Status::UNSUPPORTED_MEDIA_TYPE, $message, $headers, $details, $previous);
    }
}
