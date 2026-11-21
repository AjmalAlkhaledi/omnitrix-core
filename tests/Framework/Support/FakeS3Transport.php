<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\HttpClient\Transport\Exchange;
use Omnitrix\Core\HttpClient\Transport\RawResponse;
use Omnitrix\Core\HttpClient\Transport\TransportInterface;
use Omnitrix\Filesystem\S3\Signer;
use RuntimeException;

use function array_slice;
use function count;
use function explode;
use function file_put_contents;
use function hash;
use function htmlspecialchars;
use function implode;
use function is_string;
use function ksort;
use function md5;
use function parse_str;
use function preg_match;
use function rawurldecode;
use function sort;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;

use const ENT_XML1;

/**
 * An S3 bucket in memory behind the HTTP client. Every request must carry a SigV4 signature
 * that matches the request as it is sent on the wire (path, query, headers, body hash).
 */
final class FakeS3Transport implements TransportInterface
{
    /** @var array<string, array{body: string, type: string, modified: int}> */
    public array $objects = [];

    /** @var list<string> "METHOD path?query" */
    public array $log = [];

    /** @var array<string, array<int, string>> */
    private array $uploads = [];

    /** @var array<string, string> */
    private array $uploadTypes = [];

    /** @var int number of parts received, for assertions */
    public int $parts = 0;

    private int $nextUpload = 1;

    public function __construct(private readonly string $bucket, private readonly string $key, private readonly string $secret, private readonly string $region, private readonly bool $pathStyle = true, public int $pageSize = 1000) {}

    public function exchange(Exchange $exchange, ?CancellationToken $token = null): RawResponse
    {
        $uri = $exchange->uri;
        $this->log[] = $exchange->method . ' ' . $uri->path . ($uri->query === '' ? '' : '?' . $uri->query);
        $headers = [];
        foreach ($exchange->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }
        if (!$this->validSignature($exchange, $headers)) {
            return self::error(403, 'SignatureDoesNotMatch', 'The request signature we calculated does not match');
        }
        if (isset($headers['content-length']) && (int) $headers['content-length'] !== strlen($exchange->body)) {
            return self::error(400, 'IncompleteBody', 'Content-Length mismatch');
        }
        parse_str($uri->query, $parsed);
        $query = [];
        foreach ($parsed as $name => $value) {
            if (is_string($value)) {
                $query[(string) $name] = $value;
            }
        }
        $path = $uri->path;
        if ($this->pathStyle) {
            $prefix = '/' . $this->bucket;
            if (!str_starts_with($path, $prefix . '/') && $path !== $prefix) {
                return self::error(404, 'NoSuchBucket', 'The specified bucket does not exist');
            }
            $path = substr($path, strlen($prefix));
        }
        $key = rawurldecode(substr($path, 1));

        if ($exchange->method === 'GET' && $key === '' && ($query['list-type'] ?? null) === '2') {
            return $this->list($query['prefix'] ?? '', $query['delimiter'] ?? null, $query['continuation-token'] ?? null);
        }

        return match (true) {
            $exchange->method === 'POST' && isset($query['uploads']) => $this->startUpload($headers['content-type'] ?? 'binary/octet-stream'),
            $exchange->method === 'PUT' && isset($query['uploadId'], $query['partNumber']) => $this->uploadPart($query['uploadId'], (int) $query['partNumber'], $exchange->body),
            $exchange->method === 'POST' && isset($query['uploadId']) => $this->completeUpload($key, $query['uploadId']),
            $exchange->method === 'DELETE' && isset($query['uploadId']) => $this->abortUpload($query['uploadId']),
            $exchange->method === 'PUT' && isset($headers['x-amz-copy-source']) => $this->copy($key, $headers['x-amz-copy-source']),
            $exchange->method === 'PUT' => $this->put($key, $exchange->body, $headers['content-type'] ?? 'binary/octet-stream'),
            $exchange->method === 'GET' => $this->get($key, $exchange->downloadTo),
            $exchange->method === 'HEAD' => $this->head($key),
            $exchange->method === 'DELETE' => $this->delete($key),
            default => self::error(405, 'MethodNotAllowed', 'Unsupported'),
        };
    }

    public function stats(): array
    {
        return [];
    }

    public function close(): void {}

    /** @param array<string, string> $headers */
    private function validSignature(Exchange $exchange, array $headers): bool
    {
        $auth = $headers['authorization'] ?? '';
        if (preg_match('#^AWS4-HMAC-SHA256 Credential=([^/]+)/(\d{8})/([^/]+)/s3/aws4_request, SignedHeaders=([a-z0-9;-]+), Signature=([0-9a-f]{64})$#', $auth, $m) !== 1) {
            return false;
        }
        [, $key, , $region, $signedHeaders, $signature] = $m;
        if ($key !== $this->key || $region !== $this->region) {
            return false;
        }
        if (($headers['x-amz-content-sha256'] ?? '') !== hash('sha256', $exchange->body)) {
            return false;
        }
        $signed = [];
        foreach (explode(';', $signedHeaders) as $name) {
            if (!isset($headers[$name])) {
                return false;
            }
            $signed[$name] = $headers[$name];
        }
        if (!isset($signed['host']) || $signed['host'] !== $exchange->uri->hostHeader()) {
            return false;
        }
        // Decode the query as it arrived and sign it again.
        $query = [];
        foreach ($exchange->uri->query === '' ? [] : explode('&', $exchange->uri->query) as $pair) {
            $eq = strpos($pair, '=');
            $query[rawurldecode($eq === false ? $pair : substr($pair, 0, $eq))] = $eq === false ? '' : rawurldecode(substr($pair, $eq + 1));
        }
        ksort($query);
        $when = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $headers['x-amz-date'] ?? '', new DateTimeZone('UTC'));
        if ($when === false) {
            return false;
        }
        $expected = (new Signer($this->key, $this->secret, $this->region))->sign($exchange->method, $exchange->uri->path, $query, array_diff_key($signed, ['x-amz-date' => 1, 'x-amz-content-sha256' => 1]), $headers['x-amz-content-sha256'], $when);
        preg_match('/Signature=([0-9a-f]{64})/', $expected['Authorization'], $e);

        return ($e[1] ?? '') === $signature;
    }

    private function put(string $key, string $body, string $type): RawResponse
    {
        $this->objects[$key] = ['body' => $body, 'type' => $type, 'modified' => 1_800_000_000];

        return new RawResponse(200, ['etag' => '"' . md5($body) . '"'], '');
    }

    private function copy(string $key, string $source): RawResponse
    {
        $prefix = '/' . $this->bucket . '/';
        $from = rawurldecode(substr($source, strlen($prefix)));
        if (!str_starts_with($source, $prefix) || !isset($this->objects[$from])) {
            return self::error(404, 'NoSuchKey', 'The specified key does not exist.');
        }
        $this->objects[$key] = $this->objects[$from];

        return new RawResponse(200, ['content-type' => 'application/xml'], '<CopyObjectResult><ETag>"x"</ETag></CopyObjectResult>');
    }

    private function get(string $key, ?string $downloadTo): RawResponse
    {
        $object = $this->objects[$key] ?? null;
        if ($object === null) {
            return self::error(404, 'NoSuchKey', 'The specified key does not exist.');
        }
        if ($downloadTo !== null) {
            file_put_contents($downloadTo, $object['body']);

            return new RawResponse(200, ['content-type' => $object['type'], 'content-length' => (string) strlen($object['body'])], '');
        }

        return new RawResponse(200, ['content-type' => $object['type'], 'content-length' => (string) strlen($object['body'])], $object['body']);
    }

    private function head(string $key): RawResponse
    {
        $object = $this->objects[$key] ?? null;
        if ($object === null) {
            return new RawResponse(404, [], '');
        }

        return new RawResponse(200, ['content-type' => $object['type'], 'content-length' => (string) strlen($object['body']), 'last-modified' => 'Fri, 15 Jan 2027 08:00:00 GMT'], '');
    }

    private function delete(string $key): RawResponse
    {
        unset($this->objects[$key]);

        return new RawResponse(204, [], '');
    }

    private function list(string $prefix, ?string $delimiter, ?string $after): RawResponse
    {
        $keys = [];
        foreach ($this->objects as $key => $ignored) {
            $key = (string) $key;
            if (!str_starts_with($key, $prefix)) {
                continue;
            }
            if ($delimiter !== null && str_contains(substr($key, strlen($prefix)), $delimiter)) {
                continue; // a "common prefix", not listed as an object
            }
            $keys[] = $key;
        }
        sort($keys);
        $start = $after === null ? 0 : (int) $after;
        $page = array_slice($keys, $start, $this->pageSize);
        $truncated = $start + count($page) < count($keys);
        $xml = '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult>';
        foreach ($page as $key) {
            $xml .= '<Contents><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></Contents>';
        }
        $xml .= '<IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>' . ($truncated ? '<NextContinuationToken>' . ($start + count($page)) . '</NextContinuationToken>' : '') . '</ListBucketResult>';

        return new RawResponse(200, ['content-type' => 'application/xml'], $xml);
    }

    private function startUpload(string $type): RawResponse
    {
        $id = 'upload-' . $this->nextUpload++;
        $this->uploads[$id] = [];
        $this->uploadTypes[$id] = $type;

        return new RawResponse(200, [], "<InitiateMultipartUploadResult><UploadId>{$id}</UploadId></InitiateMultipartUploadResult>");
    }

    private function uploadPart(string $id, int $number, string $body): RawResponse
    {
        if (!isset($this->uploads[$id])) {
            return self::error(404, 'NoSuchUpload', 'Unknown upload');
        }
        $this->uploads[$id][$number] = $body;
        $this->parts++;

        return new RawResponse(200, ['etag' => '"' . md5($body) . '"'], '');
    }

    private function completeUpload(string $key, string $id): RawResponse
    {
        $type = $this->uploadTypes[$id] ?? 'binary/octet-stream';
        $parts = $this->uploads[$id] ?? throw new RuntimeException('unknown upload');
        ksort($parts);
        unset($this->uploads[$id]);
        $this->objects[$key] = ['body' => implode('', $parts), 'type' => $type, 'modified' => 1_800_000_000];

        return new RawResponse(200, [], '<CompleteMultipartUploadResult><Key>' . htmlspecialchars($key, ENT_XML1) . '</Key></CompleteMultipartUploadResult>');
    }

    private function abortUpload(string $id): RawResponse
    {
        unset($this->uploads[$id]);

        return new RawResponse(204, [], '');
    }

    private static function error(int $status, string $code, string $message): RawResponse
    {
        return new RawResponse($status, ['content-type' => 'application/xml'], "<?xml version=\"1.0\"?><Error><Code>{$code}</Code><Message>{$message}</Message></Error>");
    }
}
