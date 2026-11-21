<?php

declare(strict_types=1);

namespace Omnitrix\Http;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use Omnitrix\Core\Http\Status;

use function addslashes;
use function is_file;
use function json_encode;
use function strtolower;
use function ucwords;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The response value returned by handlers and middleware.
 *
 * A Response is a description, not a transport: nothing is sent until
 * {@see ResponseEmitter} writes it. Bodies are one of: a string, a file path (sendfile), a
 * streaming callback that receives a {@see Stream}, or an iterable of chunks.
 * Setters are fluent and mutate the instance (one response per request; no copying cost).
 */
final class Response
{
    public const int BODY_STRING = 0;
    public const int BODY_FILE = 1;
    public const int BODY_STREAM = 2;
    public const int BODY_ITERABLE = 3;

    private int $status = Status::OK;

    /** @var array<string, string> canonical name => value */
    private array $headers = [];

    /** @var list<Cookie> */
    private array $cookies = [];

    private int $bodyType = self::BODY_STRING;

    private string $body = '';

    private ?string $file = null;

    /** @var null|Closure(Stream): void */
    private ?Closure $stream = null;

    /** @var null|iterable<string> */
    private ?iterable $chunks = null;

    /** @param array<string, string> $headers */
    public function __construct(string $body = '', int $status = Status::OK, array $headers = [])
    {
        $this->body = $body;
        $this->status($status);
        foreach ($headers as $name => $value) {
            $this->header($name, $value);
        }
    }

    public static function json(mixed $data, int $status = Status::OK, int $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE): self
    {
        $body = json_encode($data instanceof JsonSerializable ? $data->jsonSerialize() : $data, $flags | JSON_THROW_ON_ERROR);

        return new self($body, $status, ['Content-Type' => 'application/json']);
    }

    public static function text(string $body, int $status = Status::OK): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = Status::OK): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function noContent(): self
    {
        return new self('', Status::NO_CONTENT);
    }

    public static function redirect(string $location, int $status = Status::FOUND): self
    {
        if ($status < 300 || $status > 399) {
            throw new InvalidArgumentException("Redirect status must be 3xx, got {$status}");
        }

        return new self('', $status, ['Location' => $location]);
    }

    /** Sends a file with sendfile(2). */
    public static function file(string $path, ?string $contentType = null, ?string $downloadName = null): self
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }
        $response = new self();
        $response->bodyType = self::BODY_FILE;
        $response->file = $path;
        $response->header('Content-Type', $contentType ?? 'application/octet-stream');
        if ($downloadName !== null) {
            $response->header('Content-Disposition', 'attachment; filename="' . addslashes($downloadName) . '"');
        }

        return $response;
    }

    /**
     * Streams through a callback that writes chunks with {@see Stream::write()}; the
     * emitter ends the response when the callback returns.
     *
     * @param callable(Stream): void $producer
     */
    public static function stream(callable $producer, int $status = Status::OK, string $contentType = 'application/octet-stream'): self
    {
        $response = new self('', $status, ['Content-Type' => $contentType]);
        $response->bodyType = self::BODY_STREAM;
        $response->stream = $producer(...);

        return $response;
    }

    /**
     * Streams every string yielded by the iterable (generators welcome) as a chunk.
     *
     * @param iterable<string> $chunks
     */
    public static function chunks(iterable $chunks, int $status = Status::OK, string $contentType = 'application/octet-stream'): self
    {
        $response = new self('', $status, ['Content-Type' => $contentType]);
        $response->bodyType = self::BODY_ITERABLE;
        $response->chunks = $chunks;

        return $response;
    }

    public function status(int $status): self
    {
        if (!Status::isValid($status)) {
            throw new InvalidArgumentException("Invalid HTTP status {$status}");
        }
        $this->status = $status;

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[ucwords(strtolower($name), '-')] = $value;

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

    public function withoutHeader(string $name): self
    {
        unset($this->headers[ucwords(strtolower($name), '-')]);

        return $this;
    }

    public function cookie(Cookie $cookie): self
    {
        $this->cookies[] = $cookie;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[ucwords(strtolower($name), '-')] ?? null;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Replaces every cookie of the response (middleware that transforms cookies, e.g. encryption).
     *
     * @param list<Cookie> $cookies
     */
    public function replaceCookies(array $cookies): self
    {
        $this->cookies = $cookies;

        return $this;
    }

    /** @return list<Cookie> */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    public function bodyType(): int
    {
        return $this->bodyType;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    /** @return null|Closure(Stream): void */
    public function getStream(): ?Closure
    {
        return $this->stream;
    }

    /** @return null|iterable<string> */
    public function getChunks(): ?iterable
    {
        return $this->chunks;
    }
}
