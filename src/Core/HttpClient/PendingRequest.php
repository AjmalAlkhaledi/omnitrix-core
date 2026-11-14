<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

use InvalidArgumentException;
use JsonException;
use Omnitrix\Core\Concurrency\CancellationToken;

use function base64_encode;
use function http_build_query;
use function json_encode;
use function strtolower;
use function strtoupper;
use function ucwords;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_QUERY_RFC3986;

/**
 * Fluent request builder.
 *
 * ```php
 * $response = Http::timeout(5)->withHeaders(['Authorization' => 'Bearer TOKEN'])->get('https://api.example.com/users');
 * $data = $response->json();
 * ```
 */
final class PendingRequest
{
    /** @var array<string, string> canonical names */
    private array $headers = ['Accept' => 'application/json, */*;q=0.8'];

    /** @var array<string, scalar|null|array<mixed>> */
    private array $query = [];

    private string $body = '';

    private ?float $timeout = null;

    private ?float $connectTimeout = null;

    private ?int $maxRedirects = null;

    private ?int $maxResponseSize = null;

    private ?bool $verifyTls = null;

    private ?string $caFile = null;

    private ?int $retries = null;

    private ?bool $retryOnTimeout = null;

    private ?bool $http2 = null;

    private ?CancellationToken $token = null;

    public function __construct(private readonly HttpClient $client)
    {
        $this->headers['User-Agent'] = $client->config->userAgent;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->headers[self::canonical($name)] = $value;
        }

        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[self::canonical($name)] = $value;

        return $this;
    }

    public function withToken(string $token, string $type = 'Bearer'): self
    {
        return $this->withHeader('Authorization', $type . ' ' . $token);
    }

    public function withBasicAuth(string $username, string $password): self
    {
        return $this->withHeader('Authorization', 'Basic ' . base64_encode($username . ':' . $password));
    }

    public function accept(string $contentType): self
    {
        return $this->withHeader('Accept', $contentType);
    }

    /** @param array<string, scalar|null|array<mixed>> $query */
    public function withQuery(array $query): self
    {
        foreach ($query as $key => $value) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /** JSON body (objects/arrays/JsonSerializable). */
    public function asJson(mixed $data): self
    {
        try {
            $this->body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Request body cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }
        $this->headers['Content-Type'] = 'application/json';

        return $this;
    }

    /**
     * application/x-www-form-urlencoded body.
     *
     * @param array<string, scalar|null|array<mixed>> $fields
     */
    public function asForm(array $fields): self
    {
        $this->body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        $this->headers['Content-Type'] = 'application/x-www-form-urlencoded';

        return $this;
    }

    /** Raw body with an explicit content type. */
    public function withBody(string $body, string $contentType): self
    {
        $this->body = $body;
        $this->headers['Content-Type'] = $contentType;

        return $this;
    }

    /** Response timeout in seconds (after the request was sent). */
    public function timeout(float $seconds): self
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function connectTimeout(float $seconds): self
    {
        $this->connectTimeout = $seconds;

        return $this;
    }

    public function maxRedirects(int $count): self
    {
        $this->maxRedirects = $count;

        return $this;
    }

    public function withoutRedirecting(): self
    {
        return $this->maxRedirects(0);
    }

    public function maxResponseSize(int $bytes): self
    {
        $this->maxResponseSize = $bytes;

        return $this;
    }

    /** Pins a CA bundle for this request (private PKI). */
    public function withCaFile(string $path): self
    {
        $this->caFile = $path;

        return $this;
    }

    /**
     * Disables certificate verification for this request only. Never use against production
     * endpoints; prefer {@see withCaFile()} for private certificate authorities.
     */
    public function withoutTlsVerification(): self
    {
        $this->verifyTls = false;

        return $this;
    }

    /** Retries (idempotent methods only) on connection failures. */
    public function retry(int $times, bool $onTimeout = false): self
    {
        $this->retries = $times;
        $this->retryOnTimeout = $onTimeout;

        return $this;
    }

    public function http2(bool $enabled = true): self
    {
        $this->http2 = $enabled;

        return $this;
    }

    public function withCancellation(CancellationToken $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function get(string $url): Response
    {
        return $this->send('GET', $url);
    }

    public function head(string $url): Response
    {
        return $this->send('HEAD', $url);
    }

    public function post(string $url): Response
    {
        return $this->send('POST', $url);
    }

    public function put(string $url): Response
    {
        return $this->send('PUT', $url);
    }

    public function patch(string $url): Response
    {
        return $this->send('PATCH', $url);
    }

    public function delete(string $url): Response
    {
        return $this->send('DELETE', $url);
    }

    public function send(string $method, string $url): Response
    {
        return $this->client->send(strtoupper($method), Uri::parse($url)->withQuery($this->query), $this->headers, $this->body, $this->options(), $this->token);
    }

    /** Streams the response body into `$path` (not buffered; size limit does not apply). */
    public function download(string $url, string $path): Response
    {
        return $this->client->send('GET', Uri::parse($url)->withQuery($this->query), $this->headers, '', $this->options($path), $this->token);
    }

    private function options(?string $downloadTo = null): RequestOptions
    {
        return new RequestOptions($this->timeout, $this->connectTimeout, $this->maxRedirects, $this->maxResponseSize, $this->verifyTls, $this->caFile, $this->retries, $this->retryOnTimeout, $this->http2, $downloadTo);
    }

    private static function canonical(string $name): string
    {
        return ucwords(strtolower($name), '-');
    }
}
