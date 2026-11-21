<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem\S3;

use DateTimeImmutable;
use DateTimeZone;

use function array_keys;
use function array_map;
use function explode;
use function hash;
use function hash_hmac;
use function implode;
use function ksort;
use function preg_replace;
use function rawurlencode;
use function strtolower;
use function trim;

use const SORT_STRING;

/**
 * AWS Signature Version 4 for S3 (headers and presigned URLs). Works with any S3-compatible
 * service that checks SigV4 (AWS, Cloudflare R2, Backblaze B2, Garage, Ceph, …).
 */
final readonly class Signer
{
    public const string UNSIGNED = 'UNSIGNED-PAYLOAD';

    public function __construct(
        private string $key,
        private string $secret,
        private string $region,
        private ?string $sessionToken = null,
        private string $service = 's3',
    ) {}

    /**
     * Headers to add to the request: `Authorization`, `x-amz-date`, `x-amz-content-sha256`
     * (and `x-amz-security-token`). `$path` is already URI-encoded; `$headers` must include
     * `host` and are all signed.
     *
     * @param array<string, string> $query   decoded names and values
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public function sign(string $method, string $path, array $query, array $headers, string $payloadHash, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $added = ['x-amz-date' => $amzDate, 'x-amz-content-sha256' => $payloadHash];
        if ($this->sessionToken !== null) {
            $added['x-amz-security-token'] = $this->sessionToken;
        }
        $canonicalHeaders = self::headers($headers + $added);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));
        $canonical = implode("\n", [$method, $path, self::query($query), self::headerBlock($canonicalHeaders), $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $signature = $this->signature($date, "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical));

        return $added + ['Authorization' => "AWS4-HMAC-SHA256 Credential={$this->key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}"];
    }

    /**
     * The query string of a presigned URL (only `host` is signed, the payload is unsigned).
     *
     * @param array<string, string> $query extra parameters (response-content-disposition, …)
     */
    public function presign(string $method, string $path, string $host, int $expires, array $query = [], ?DateTimeImmutable $now = null): string
    {
        $now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
        $amzDate = $now->format('Ymd\THis\Z');
        $date = $now->format('Ymd');
        $scope = "{$date}/{$this->region}/{$this->service}/aws4_request";
        $query += [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => "{$this->key}/{$scope}",
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        if ($this->sessionToken !== null) {
            $query['X-Amz-Security-Token'] = $this->sessionToken;
        }
        $canonical = implode("\n", [$method, $path, self::query($query), "host:{$host}\n", 'host', self::UNSIGNED]);
        $signature = $this->signature($date, "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonical));

        return self::query($query) . '&X-Amz-Signature=' . $signature;
    }

    /** RFC 3986 encoding of every path segment (`/` kept). */
    public static function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /** @param array<string, string> $query */
    public static function query(array $query): string
    {
        $encoded = [];
        foreach ($query as $name => $value) {
            $encoded[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($encoded, SORT_STRING);
        $pairs = [];
        foreach ($encoded as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('&', $pairs);
    }

    private function signature(string $date, string $stringToSign): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', $this->service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $stringToSign, $key);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string> lower-case names, sorted, values trimmed with inner spaces folded
     */
    private static function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[strtolower((string) $name)] = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @param array<string, string> $headers */
    private static function headerBlock(array $headers): string
    {
        $block = '';
        foreach ($headers as $name => $value) {
            $block .= $name . ':' . $value . "\n";
        }

        return $block;
    }
}
