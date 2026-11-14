<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

use InvalidArgumentException;

use function http_build_query;
use function is_int;
use function is_string;
use function parse_url;
use function preg_replace;
use function str_starts_with;
use function strtolower;

use const PHP_QUERY_RFC3986;

/** Parsed absolute http(s) URL with helpers for query merging and redirect resolution. */
final readonly class Uri
{
    private function __construct(
        public string $scheme,
        public string $host,
        public int $port,
        public string $path,
        public string $query,
    ) {}

    public static function parse(string $url): self
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !is_string($parts['scheme']) || !is_string($parts['host'])) {
            throw new InvalidArgumentException("Invalid absolute URL '{$url}'");
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new InvalidArgumentException("Unsupported URL scheme '{$scheme}' (http, https)");
        }
        $port = $parts['port'] ?? null;
        $port = is_int($port) ? $port : ($scheme === 'https' ? 443 : 80);
        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/';
        $query = isset($parts['query']) && is_string($parts['query']) ? $parts['query'] : '';

        return new self($scheme, strtolower($parts['host']), $port, $path, $query);
    }

    public function isTls(): bool
    {
        return $this->scheme === 'https';
    }

    /** Request target (path + query) as sent on the wire. */
    public function target(): string
    {
        return $this->path . ($this->query === '' ? '' : '?' . $this->query);
    }

    public function hostHeader(): string
    {
        $default = $this->scheme === 'https' ? 443 : 80;

        return $this->port === $default ? $this->host : $this->host . ':' . $this->port;
    }

    /** Origin key used for connection pooling. */
    public function origin(): string
    {
        return $this->scheme . '://' . $this->host . ':' . $this->port;
    }

    public function __toString(): string
    {
        return $this->origin() . $this->target();
    }

    /** @param array<string, scalar|null|array<mixed>> $params */
    public function withQuery(array $params): self
    {
        if ($params === []) {
            return $this;
        }
        $extra = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $query = $this->query === '' ? $extra : $this->query . '&' . $extra;

        return new self($this->scheme, $this->host, $this->port, $this->path, $query);
    }

    /** Resolves a Location header against this URL (absolute, protocol-relative, absolute-path or relative). */
    public function resolve(string $location): self
    {
        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return self::parse($location);
        }
        if (str_starts_with($location, '//')) {
            return self::parse($this->scheme . ':' . $location);
        }
        if (str_starts_with($location, '/')) {
            return self::parse($this->origin() . $location);
        }
        $base = preg_replace('#/[^/]*$#', '/', $this->path) ?? '/';

        return self::parse($this->origin() . $base . $location);
    }
}
