<?php

declare(strict_types=1);

namespace Omnitrix\Routing;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Omnitrix\Http\Request;

use function array_key_exists;
use function hash_equals;
use function hash_hmac;
use function http_build_query;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function ksort;
use function ltrim;
use function parse_str;
use function preg_match;
use function preg_replace_callback;
use function rawurlencode;
use function rtrim;
use function str_starts_with;
use function time;

use const PHP_QUERY_RFC3986;

/**
 * URLs to paths and named routes, and signed URLs.
 *
 * ```php
 * $url->route('users.show', ['id' => 10, 'tab' => 'posts']);        // https://api.example.com/api/users/10?tab=posts
 * $url->signedRoute('verification.verify', ['id' => 10], 3600);     // valid for an hour
 * $url->hasValidSignature($request);
 * ```
 *
 * Route parameters fill the pattern's placeholders (`{id}`, `{id:\d+}` — values are checked
 * against the placeholder's pattern); the rest become the query string. URLs are absolute when
 * `app.url` is configured. Signatures are HMAC-SHA256 over the path and the sorted query, keyed
 * from `app.key` (separately from encryption), so changing any parameter invalidates them.
 */
final class UrlGenerator implements \Omnitrix\Contracts\UrlGenerator
{
    private readonly ?string $signingKey;

    public function __construct(private readonly Router $router, private readonly string $baseUrl = '', ?string $appKey = null)
    {
        $this->signingKey = $appKey === null || $appKey === '' ? null : hash_hmac('sha256', 'omnitrix:url-signature', $appKey, true);
    }

    /** @param array<string, scalar|null> $query */
    public function to(string $path, array $query = []): string
    {
        $url = ($this->baseUrl === '' ? '' : rtrim($this->baseUrl, '/')) . '/' . ltrim($path, '/');

        return $query === [] ? $url : $url . '?' . self::query($query);
    }

    /** @param array<string, scalar|null> $parameters */
    public function route(string $name, array $parameters = []): string
    {
        [$path, $query] = $this->path($name, $parameters);

        return $this->to($path, $query);
    }

    /**
     * A named route URL carrying a signature (and an expiry when `$expiration` is given: seconds
     * from now, or a point in time).
     *
     * @param array<string, scalar|null> $parameters
     */
    public function signedRoute(string $name, array $parameters = [], int|DateTimeInterface|null $expiration = null): string
    {
        if (array_key_exists('signature', $parameters) || array_key_exists('expires', $parameters)) {
            throw new InvalidArgumentException("'signature' and 'expires' are reserved parameters of signed URLs");
        }
        [$path, $query] = $this->path($name, $parameters);
        if ($expiration !== null) {
            $query['expires'] = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() : time() + $expiration;
        }
        $query['signature'] = $this->signature($path, $query);

        return $this->to($path, $query);
    }

    /** @param array<string, scalar|null> $parameters */
    public function temporarySignedRoute(string $name, int|DateTimeInterface $expiration, array $parameters = []): string
    {
        return $this->signedRoute($name, $parameters, $expiration);
    }

    /** Whether the request URL carries a valid, unexpired signature. */
    public function hasValidSignature(Request $request): bool
    {
        return $this->hasCorrectSignature($request) && !$this->signatureHasExpired($request);
    }

    public function hasCorrectSignature(Request $request): bool
    {
        parse_str($request->queryString(), $query);
        $signature = $query['signature'] ?? null;
        if (!is_string($signature)) {
            return false;
        }
        unset($query['signature']);
        $clean = [];
        foreach ($query as $key => $value) {
            if (!is_scalar($value)) {
                return false;
            }
            $clean[(string) $key] = $value;
        }

        return hash_equals($this->signature($request->path(), $clean), $signature);
    }

    public function signatureHasExpired(Request $request): bool
    {
        parse_str($request->queryString(), $query);
        $expires = $query['expires'] ?? null;

        return $expires !== null && (!is_string($expires) || !is_numeric($expires) || (int) $expires < time());
    }

    /**
     * @param array<string, scalar|null> $parameters
     *
     * @return array{string, array<string, scalar|null>}
     */
    private function path(string $name, array $parameters): array
    {
        $route = null;
        foreach ($this->router->routes() as $candidate) {
            if ($candidate->getName() === $name) {
                $route = $candidate;
                break;
            }
        }
        if ($route === null) {
            throw new InvalidArgumentException("Route '{$name}' is not defined");
        }
        $used = [];
        $path = (string) preg_replace_callback('/\{([A-Za-z_][A-Za-z0-9_]*)(?::((?:[^{}]|\{[^{}]*\})+))?\}/', static function (array $m) use ($parameters, $name, &$used): string {
            $key = $m[1];
            if (!array_key_exists($key, $parameters) || $parameters[$key] === null) {
                throw new InvalidArgumentException("Route '{$name}' needs the parameter '{$key}'");
            }
            $value = (string) $parameters[$key];
            $pattern = $m[2] ?? '';
            if ($pattern !== '' && preg_match('#^(?:' . $pattern . ')$#', $value) !== 1) {
                throw new InvalidArgumentException("Parameter '{$key}' of route '{$name}' does not match {$pattern}");
            }
            $used[$key] = true;

            return rawurlencode($value);
        }, $route->pattern);
        $query = [];
        foreach ($parameters as $key => $value) {
            if (!isset($used[$key]) && $value !== null) {
                $query[$key] = $value;
            }
        }

        return [$path, $query];
    }

    /** @param array<string, scalar|null> $query */
    private function signature(string $path, array $query): string
    {
        if ($this->signingKey === null) {
            throw new LogicException('Signed URLs need app.key (php omnitrix key:generate)');
        }
        ksort($query);
        $path = str_starts_with($path, '/') ? $path : '/' . $path;

        return hash_hmac('sha256', $path . '?' . self::query($query), $this->signingKey);
    }

    /** @param array<string, scalar|null> $query */
    private static function query(array $query): string
    {
        $normal = [];
        foreach ($query as $key => $value) {
            if ($value !== null) {
                $normal[$key] = is_int($value) ? (string) $value : (is_string($value) ? $value : (string) (int) $value);
            }
        }

        return http_build_query($normal, '', '&', PHP_QUERY_RFC3986);
    }
}
