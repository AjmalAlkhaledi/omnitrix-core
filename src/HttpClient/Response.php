<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

use JsonException;
use Omnitrix\Core\HttpClient\Exception\HttpClientException;
use Omnitrix\Core\HttpClient\Exception\RequestException;

use function array_key_exists;
use function is_array;
use function is_int;
use function json_decode;
use function strlen;
use function strtolower;

use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;

/** An outbound HTTP response (fully buffered body). */
final class Response
{
    private mixed $json = null;

    private bool $jsonDecoded = false;

    /**
     * @param array<string, string> $headers lower-case names, multi-value headers joined by ", "
     * @param list<string>          $setCookies raw Set-Cookie header values
     */
    public function __construct(
        private readonly int $status,
        private readonly array $headers,
        private readonly string $body,
        private readonly string $url,
        private readonly array $setCookies = [],
        private readonly int $redirects = 0,
        private readonly float $elapsed = 0.0,
        private readonly string $protocol = '1.1',
    ) {}

    public function status(): int
    {
        return $this->status;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function successful(): bool
    {
        return $this->ok();
    }

    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }

    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }

    public function serverError(): bool
    {
        return $this->status >= 500;
    }

    public function failed(): bool
    {
        return $this->status >= 400;
    }

    /** Throws {@see RequestException} for 4xx/5xx; returns $this otherwise. */
    public function throw(): self
    {
        if ($this->failed()) {
            throw new RequestException($this);
        }

        return $this;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists(strtolower($name), $this->headers);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<string> */
    public function setCookies(): array
    {
        return $this->setCookies;
    }

    public function contentType(): ?string
    {
        return $this->headers['content-type'] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function size(): int
    {
        return strlen($this->body);
    }

    /**
     * Decoded JSON body (objects as arrays), decoded once and cached.
     *
     * @throws HttpClientException when the body is not valid JSON
     */
    public function json(): mixed
    {
        if (!$this->jsonDecoded) {
            try {
                $this->json = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $e) {
                throw new HttpClientException("Response from {$this->url} is not valid JSON: {$e->getMessage()}", 0, $e);
            }
            $this->jsonDecoded = true;
        }

        return $this->json;
    }

    /**
     * JSON object body as a string-keyed array.
     *
     * @return array<string, mixed>
     */
    public function jsonObject(): array
    {
        $decoded = $this->json();
        if (!is_array($decoded)) {
            throw new HttpClientException("Response from {$this->url} is not a JSON object");
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            if (is_int($key)) {
                throw new HttpClientException("Response from {$this->url} is a JSON array, expected an object");
            }
            $object[$key] = $value;
        }

        return $object;
    }

    /** Final URL after redirects. */
    public function url(): string
    {
        return $this->url;
    }

    public function redirectCount(): int
    {
        return $this->redirects;
    }

    /** Wall-clock seconds for the whole exchange including redirects and retries. */
    public function elapsed(): float
    {
        return $this->elapsed;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }
}
