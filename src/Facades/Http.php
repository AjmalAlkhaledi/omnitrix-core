<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\PendingRequest;
use Omnitrix\Core\HttpClient\Response;

/**
 * Outbound HTTP (see docs/http-client.md).
 *
 * ```php
 * $data = Http::timeout(5)->withHeaders(['Authorization' => 'Bearer TOKEN'])->get('https://api.example.com/users')->json();
 * ```
 */
final class Http extends Facade
{
    public static function client(): HttpClient
    {
        return self::resolve(HttpClient::class);
    }

    public static function request(): PendingRequest
    {
        return self::client()->request();
    }

    /** @param array<string, string> $headers */
    public static function withHeaders(array $headers): PendingRequest
    {
        return self::request()->withHeaders($headers);
    }

    public static function withToken(string $token, string $type = 'Bearer'): PendingRequest
    {
        return self::request()->withToken($token, $type);
    }

    public static function timeout(float $seconds): PendingRequest
    {
        return self::request()->timeout($seconds);
    }

    public static function retry(int $times, bool $onTimeout = false): PendingRequest
    {
        return self::request()->retry($times, $onTimeout);
    }

    /** @param array<string, scalar|null|array<mixed>> $query */
    public static function get(string $url, array $query = []): Response
    {
        return self::request()->withQuery($query)->get($url);
    }

    public static function post(string $url, mixed $json = null): Response
    {
        $request = self::request();

        return ($json === null ? $request : $request->asJson($json))->post($url);
    }

    public static function put(string $url, mixed $json = null): Response
    {
        $request = self::request();

        return ($json === null ? $request : $request->asJson($json))->put($url);
    }

    public static function patch(string $url, mixed $json = null): Response
    {
        $request = self::request();

        return ($json === null ? $request : $request->asJson($json))->patch($url);
    }

    public static function delete(string $url): Response
    {
        return self::request()->delete($url);
    }

    public static function head(string $url): Response
    {
        return self::request()->head($url);
    }
}
