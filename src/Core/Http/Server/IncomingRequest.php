<?php

declare(strict_types=1);

namespace Omnitrix\Core\Http\Server;

use JsonException;
use OpenSwoole\Http\Request;

use function array_key_exists;
use function explode;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function max;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;

/**
 * Transport-level view of an HTTP request parsed by OpenSwoole.
 *
 * All accessors are cheap: header keys are already lower-cased by the parser, the body is read
 * once, and JSON is decoded lazily on first {@see json()} call (with a depth limit) and cached.
 * Nothing here is mutable from the outside; higher layers (routing, validation) wrap it.
 */
final class IncomingRequest
{
    /** @var array<string, string> lower-case header name => value (multi-value headers joined by ", ") */
    private readonly array $headers;

    /** @var array<string, mixed> */
    private readonly array $query;

    /** @var array<string, string> */
    private readonly array $cookies;

    /** @var array<string, mixed> */
    private readonly array $server;

    private readonly string $method;

    private readonly string $path;

    private ?string $body = null;

    private mixed $json = null;

    private bool $jsonDecoded = false;

    private readonly int $jsonMaxDepth;

    public function __construct(private readonly Request $raw, int $jsonMaxDepth = 64)
    {
        $this->jsonMaxDepth = max(1, $jsonMaxDepth);
        $this->server = self::stringKeyed(is_array($raw->server) ? $raw->server : []);
        $this->headers = self::normaliseHeaders(is_array($raw->header) ? $raw->header : []);
        $this->query = self::stringKeyed(is_array($raw->get) ? $raw->get : []);
        $this->cookies = self::stringMap(is_array($raw->cookie) ? $raw->cookie : []);

        $method = $this->server['request_method'] ?? 'GET';
        $this->method = strtoupper(is_string($method) ? $method : 'GET');

        $uri = $this->server['request_uri'] ?? '/';
        $path = is_string($uri) && $uri !== '' ? $uri : '/';
        $q = strpos($path, '?');
        $this->path = $q === false ? $path : substr($path, 0, $q);
    }

    public function raw(): Request
    {
        return $this->raw;
    }

    /** Upper-case HTTP method. */
    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /** Path without query string, as sent (not decoded, not normalised). */
    public function path(): string
    {
        return $this->path;
    }

    public function queryString(): string
    {
        $qs = $this->server['query_string'] ?? '';

        return is_string($qs) ? $qs : '';
    }

    /**
     * Parsed query parameters (PHP array syntax supported).
     *
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    public function queryParam(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->query) ? $this->query[$name] : $default;
    }

    /** HTTP protocol version such as "1.1" or "2". */
    public function protocolVersion(): string
    {
        $proto = $this->server['server_protocol'] ?? 'HTTP/1.1';

        return is_string($proto) && str_contains($proto, '/') ? explode('/', $proto, 2)[1] : '1.1';
    }

    /** @return array<string, string> lower-case names */
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    /** Media type of the body without parameters, lower-case (e.g. "application/json"), or null. */
    public function contentType(): ?string
    {
        $header = $this->headers['content-type'] ?? null;
        if ($header === null) {
            return null;
        }
        $semi = strpos($header, ';');

        return strtolower(trim($semi === false ? $header : substr($header, 0, $semi)));
    }

    public function isJson(): bool
    {
        $type = $this->contentType();

        return $type !== null && ($type === 'application/json' || str_ends_with($type, '+json'));
    }

    /** Declared Content-Length, or null when absent/invalid. */
    public function declaredContentLength(): ?int
    {
        $value = $this->headers['content-length'] ?? null;
        if ($value === null || $value === '' || !ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    /** Raw request body (empty string when none). */
    public function body(): string
    {
        if ($this->body === null) {
            $content = $this->raw->rawContent();
            $this->body = is_string($content) ? $content : '';
        }

        return $this->body;
    }

    public function bodyLength(): int
    {
        return strlen($this->body());
    }

    /**
     * Decoded JSON body (objects as associative arrays). Decoded once; an empty body decodes to
     * null. Invalid JSON or excessive nesting raises a 400 {@see RequestRejectedException}.
     *
     * @throws RequestRejectedException
     */
    public function json(): mixed
    {
        if ($this->jsonDecoded) {
            return $this->json;
        }

        $body = $this->body();
        if (trim($body) === '') {
            $this->jsonDecoded = true;

            return null;
        }

        try {
            $this->json = json_decode($body, true, $this->jsonMaxDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $e) {
            throw RequestRejectedException::badRequest('Malformed JSON body: ' . $e->getMessage(), $e);
        }
        $this->jsonDecoded = true;

        return $this->json;
    }

    /**
     * JSON body that must be an object (associative array).
     *
     * @return array<string, mixed>
     *
     * @throws RequestRejectedException
     */
    public function jsonObject(): array
    {
        $decoded = $this->json();
        if (!is_array($decoded)) {
            throw RequestRejectedException::badRequest('JSON body must be an object');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (is_int($key)) {
                throw RequestRejectedException::badRequest('JSON body must be an object, got an array');
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /**
     * Parsed form fields for application/x-www-form-urlencoded and multipart bodies.
     *
     * @return array<string, mixed>
     */
    public function form(): array
    {
        return self::stringKeyed(is_array($this->raw->post) ? $this->raw->post : []);
    }

    /**
     * Uploaded files as parsed by OpenSwoole.
     *
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return self::stringKeyed(is_array($this->raw->files) ? $this->raw->files : []);
    }

    public function remoteAddress(): ?string
    {
        $addr = $this->server['remote_addr'] ?? null;

        return is_string($addr) ? $addr : null;
    }

    public function remotePort(): ?int
    {
        $port = $this->server['remote_port'] ?? null;

        return is_int($port) ? $port : null;
    }

    /** Server-side receive time (float unix timestamp). */
    public function receivedAt(): float
    {
        $t = $this->server['request_time_float'] ?? null;

        return is_float($t) ? $t : microtime(true);
    }

    /** OpenSwoole connection descriptor. */
    public function connectionId(): int
    {
        return is_int($this->raw->fd) ? $this->raw->fd : 0;
    }

    /**
     * @param array<mixed> $headers
     * @return array<string, string>
     */
    private static function normaliseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name)) {
                continue;
            }
            if (is_array($value)) {
                $parts = [];
                foreach ($value as $v) {
                    if (is_string($v)) {
                        $parts[] = $v;
                    }
                }
                $value = implode(', ', $parts);
            }
            if (is_string($value)) {
                $out[strtolower($name)] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $map
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * @param array<mixed> $map
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
