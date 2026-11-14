<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

/**
 * The transport the {@see ResponseWriter} writes to. The OpenSwoole implementation wraps
 * `OpenSwoole\Http\Response`; tests use an in-memory sink. Every method mirrors one OpenSwoole
 * call so the writer adds no buffering of its own.
 */
interface ResponseSinkInterface
{
    public function status(int $code, string $reason): void;

    public function header(string $name, string $value): void;

    public function cookie(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httpOnly, string $sameSite): void;

    /** Sends a chunk (chunked transfer encoding). Returns false when the client is gone. */
    public function write(string $chunk): bool;

    /** Sends the final (or only) body and completes the response. */
    public function end(string $body): bool;

    /** Sends a file via sendfile(2); completes the response. */
    public function sendFile(string $path, int $offset, int $length): bool;
}
