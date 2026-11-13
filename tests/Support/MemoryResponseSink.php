<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

use Omnitrix\Core\Http\Server\ResponseSinkInterface;

/** In-memory {@see ResponseSinkInterface} recording everything the writer sends. */
final class MemoryResponseSink implements ResponseSinkInterface
{
    public ?int $status = null;

    public ?string $reason = null;

    /** @var array<string, string> */
    public array $headers = [];

    /** @var list<array<string, mixed>> */
    public array $cookies = [];

    /** @var list<string> */
    public array $chunks = [];

    public ?string $finalBody = null;

    public ?string $sentFile = null;

    public bool $ended = false;

    /** Simulates a disconnected client: every send fails. */
    public bool $clientGone = false;

    public function status(int $code, string $reason): void
    {
        $this->status = $code;
        $this->reason = $reason;
    }

    public function header(string $name, string $value): void
    {
        $this->headers[$name] = $value;
    }

    public function cookie(string $name, string $value, int $expires, string $path, string $domain, bool $secure, bool $httpOnly, string $sameSite): void
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'expires' => $expires, 'path' => $path, 'domain' => $domain, 'secure' => $secure, 'httpOnly' => $httpOnly, 'sameSite' => $sameSite];
    }

    public function write(string $chunk): bool
    {
        if ($this->clientGone) {
            return false;
        }
        $this->chunks[] = $chunk;

        return true;
    }

    public function end(string $body): bool
    {
        $this->ended = true;
        if ($this->clientGone) {
            return false;
        }
        $this->finalBody = $body;

        return true;
    }

    public function sendFile(string $path, int $offset, int $length): bool
    {
        $this->ended = true;
        if ($this->clientGone) {
            return false;
        }
        $this->sentFile = $path;

        return true;
    }

    public function body(): string
    {
        return implode('', $this->chunks) . ($this->finalBody ?? '');
    }
}
