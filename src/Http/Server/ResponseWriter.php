<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use InvalidArgumentException;
use JsonException;
use Omnitrix\Core\Http\Status;

use function is_file;
use function json_encode;
use function max;
use function strlen;
use function strtolower;
use function ucwords;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Builds and sends one HTTP response.
 *
 * Headers and status are buffered until the first byte goes out; the body is never buffered:
 * {@see write()} streams chunks (chunked transfer encoding), {@see end()} completes the
 * response. After the response ended, or once the transport reported the client gone, every
 * send returns false and nothing else happens — handlers can keep running safely.
 */
final class ResponseWriter
{
    private int $status = Status::OK;

    /** @var array<string, string> canonical name => value */
    private array $headers = [];

    private bool $headersSent = false;

    private bool $ended = false;

    private bool $clientGone = false;

    private int $bytesWritten = 0;

    public function __construct(private readonly ResponseSinkInterface $sink, private readonly bool $headOnly = false, private readonly int $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) {}

    public function status(int $code): self
    {
        if (!Status::isValid($code)) {
            throw new InvalidArgumentException("Invalid HTTP status {$code}");
        }
        $this->assertHeadersNotSent();
        $this->status = $code;

        return $this;
    }

    public function statusCode(): int
    {
        return $this->status;
    }

    public function header(string $name, string $value): self
    {
        $this->assertHeadersNotSent();
        $this->headers[self::canonical($name)] = $value;

        return $this;
    }

    /** @param array<string, string> $headers */
    public function headers(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }

        return $this;
    }

    public function removeHeader(string $name): self
    {
        $this->assertHeadersNotSent();
        unset($this->headers[self::canonical($name)]);

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[self::canonical($name)]);
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[self::canonical($name)] ?? null;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, string $value, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax'): self
    {
        $this->assertHeadersNotSent();
        $this->sink->cookie($name, $value, $expires, $path, $domain, $secure, $httpOnly, $sameSite);

        return $this;
    }

    /** Streams a chunk. The first call commits status and headers. */
    public function write(string $chunk): bool
    {
        if ($this->ended || $this->clientGone) {
            return false;
        }
        $this->flushHeaders();
        if ($this->headOnly || $chunk === '') {
            return true;
        }
        if (!$this->sink->write($chunk)) {
            $this->clientGone = true;

            return false;
        }
        $this->bytesWritten += strlen($chunk);

        return true;
    }

    /** Sends the final body (or an empty body) and ends the response. */
    public function end(string $body = ''): bool
    {
        if ($this->ended) {
            return false;
        }
        $this->ended = true;
        if ($this->clientGone) {
            return false;
        }
        if (!$this->headersSent && $this->headOnly && $body !== '' && !isset($this->headers['Content-Length'])) {
            $this->headers['Content-Length'] = (string) strlen($body);
        }
        $streaming = $this->headersSent;
        $this->flushHeaders();
        if ($this->headOnly) {
            $body = '';
        }
        // Once write() started chunked mode OpenSwoole ignores the body given to end()
        // so the final body goes out as a last chunk instead.
        $ok = $streaming && $body !== '' ? ($this->sink->write($body) && $this->sink->end('')) : $this->sink->end($body);
        if (!$ok) {
            $this->clientGone = true;
        } else {
            $this->bytesWritten += strlen($body);
        }

        return $ok;
    }

    /**
     * Ends with a JSON body.
     *
     * @throws JsonException when the value cannot be encoded
     */
    public function json(mixed $data, int $status = Status::OK): bool
    {
        $body = json_encode($data, $this->jsonFlags | JSON_THROW_ON_ERROR);
        $this->status($status)->header('Content-Type', 'application/json');

        return $this->end($body);
    }

    public function text(string $body, int $status = Status::OK, string $contentType = 'text/plain; charset=utf-8'): bool
    {
        $this->status($status)->header('Content-Type', $contentType);

        return $this->end($body);
    }

    public function noContent(): bool
    {
        $this->status(Status::NO_CONTENT);

        return $this->end();
    }

    public function redirect(string $location, int $status = Status::FOUND): bool
    {
        $this->status($status)->header('Location', $location);

        return $this->end();
    }

    /** Sends a file with sendfile(2). `$length` 0 means "to the end of the file". */
    public function sendFile(string $path, int $offset = 0, int $length = 0): bool
    {
        if ($this->ended || $this->clientGone) {
            return false;
        }
        if (!is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }
        $this->ended = true;
        $this->flushHeaders();
        if ($this->headOnly) {
            return $this->sink->end('');
        }
        $ok = $this->sink->sendFile($path, max(0, $offset), max(0, $length));
        if (!$ok) {
            $this->clientGone = true;
        }

        return $ok;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function headersSent(): bool
    {
        return $this->headersSent;
    }

    /** True once the transport reported that the client disconnected. */
    public function clientGone(): bool
    {
        return $this->clientGone;
    }

    public function bytesWritten(): int
    {
        return $this->bytesWritten;
    }

    private function flushHeaders(): void
    {
        if ($this->headersSent) {
            return;
        }
        $this->headersSent = true;
        $this->sink->status($this->status, Status::phrase($this->status));
        foreach ($this->headers as $name => $value) {
            $this->sink->header($name, $value);
        }
    }

    private function assertHeadersNotSent(): void
    {
        if ($this->headersSent) {
            throw new HeadersAlreadySentException('Headers were already sent; status, headers and cookies cannot change after the first write()');
        }
    }

    private static function canonical(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }
}
