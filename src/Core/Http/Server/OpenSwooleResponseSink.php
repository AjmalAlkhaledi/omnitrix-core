<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use OpenSwoole\Http\Response;

/** {@see ResponseSinkInterface} over the live OpenSwoole response. */
final class OpenSwooleResponseSink implements ResponseSinkInterface
{
    public function __construct(private readonly Response $response) {}

    public function status(int $code, string $reason): void
    {
        $this->response->status($code, $reason);
    }

    public function header(string $name, string $value): void
    {
        $this->response->header($name, $value);
    }

    public function cookie(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httpOnly, string $sameSite): void
    {
        $this->response->cookie($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite);
    }

    public function write(string $chunk): bool
    {
        return $this->response->write($chunk) === true;
    }

    public function end(string $body): bool
    {
        return $this->response->end($body) !== false;
    }

    public function sendFile(string $path, int $offset, int $length): bool
    {
        return $this->response->sendfile($path, $offset, $length) === true;
    }
}
