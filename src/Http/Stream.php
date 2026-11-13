<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Omnitrix\Core\Http\Server\ResponseWriter;

/**
 * The body of a streaming response, handed to the producer of {@see Response::stream()}.
 *
 * Each `write()` sends one chunk immediately (chunked transfer encoding). It returns false once
 * the client has disconnected; producers should stop generating data at that point.
 */
final class Stream
{
    /** @internal created by the framework for each streaming response */
    public function __construct(private readonly ResponseWriter $writer) {}

    /** Sends a chunk; false when the client is gone. */
    public function write(string $chunk): bool
    {
        return $this->writer->write($chunk);
    }

    /** True once a write reported that the client disconnected. */
    public function clientGone(): bool
    {
        return $this->writer->clientGone();
    }

    /** Bytes sent so far. */
    public function bytesWritten(): int
    {
        return $this->writer->bytesWritten();
    }
}
