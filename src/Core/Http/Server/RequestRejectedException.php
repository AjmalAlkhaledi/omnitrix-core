<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use Omnitrix\Core\Http\HttpErrorInterface;
use Omnitrix\Core\Http\Status;
use RuntimeException;
use Throwable;

/** The transport rejected a request before it reached application code (malformed body, size limit). */
final class RequestRejectedException extends RuntimeException implements HttpErrorInterface
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $details
     */
    public function __construct(private readonly int $status, string $message, private readonly array $headers = [], private readonly array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public static function badRequest(string $message, ?Throwable $previous = null): self
    {
        return new self(Status::BAD_REQUEST, $message, [], [], $previous);
    }

    public static function payloadTooLarge(int $limit): self
    {
        return new self(Status::PAYLOAD_TOO_LARGE, 'Request body exceeds the allowed size', [], ['max_body_size' => $limit]);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function details(): array
    {
        return $this->details;
    }
}
