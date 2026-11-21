<?php

declare(strict_types=1);

namespace Omnitrix\Filesystem\S3;

use DateTimeInterface;
use InvalidArgumentException;
use Omnitrix\Contracts\Filesystem as Disk;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\RequestOptions;
use Omnitrix\Core\HttpClient\Response as HttpResponse;
use Omnitrix\Core\HttpClient\Uri;
use Omnitrix\Core\IO\Filesystem;
use Omnitrix\Filesystem\FileNotFoundException;
use Omnitrix\Filesystem\FilesystemException;
use Omnitrix\Filesystem\MimeTypes;
use Omnitrix\Filesystem\Path;
use Omnitrix\Filesystem\ServesFiles;
use Omnitrix\Http\Response;
use Omnitrix\Http\UploadedFile;
use SimpleXMLElement;
use Throwable;

use function basename;
use function fclose;
use function feof;
use function filesize;
use function fopen;
use function fread;
use function hash;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_file;
use function is_int;
use function is_string;
use function max;
use function min;
use function parse_url;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function trim;
use function unlink;

use const ENT_XML1;
use const PHP_INT_MAX;

/**
 * An S3-compatible bucket (AWS S3, Cloudflare R2, Backblaze B2, Garage, Ceph, DigitalOcean
 * Spaces, …) over the framework's HTTP client, signed with SigV4. Files larger than
 * `multipart_threshold` are uploaded in parts; `response()` redirects to a short-lived
 * presigned URL instead of proxying the file through the worker.
 */
final class S3Disk implements Disk
{
    private readonly Signer $signer;

    private readonly string $scheme;

    private readonly string $host;

    private readonly string $prefix;

    private readonly Filesystem $io;

    /**
     * @param array<string, mixed> $config key, secret, region, bucket, endpoint?, use_path_style_endpoint?,
     *                                     url?, root?, token?, visibility?, multipart_threshold?, part_size?
     */
    public function __construct(private readonly string $name, array $config, private readonly HttpClient $http)
    {
        foreach (['key', 'secret', 'region', 'bucket'] as $required) {
            if (!is_string($config[$required] ?? null) || $config[$required] === '') {
                throw new InvalidArgumentException("Disk '{$name}': '{$required}' is required for the s3 driver");
            }
        }
        $this->config = $config;
        $key = (string) $config['key'];
        $secret = (string) $config['secret'];
        $region = (string) $config['region'];
        $this->bucket = (string) $config['bucket'];
        $token = $config['token'] ?? null;
        $this->signer = new Signer($key, $secret, $region, is_string($token) && $token !== '' ? $token : null);
        $endpoint = is_string($config['endpoint'] ?? null) && $config['endpoint'] !== '' ? rtrim($config['endpoint'], '/') : null;
        $pathStyle = $config['use_path_style_endpoint'] ?? ($endpoint !== null);
        $parts = parse_url($endpoint ?? "https://s3.{$region}.amazonaws.com");
        if (!is_array($parts) || !is_string($parts['host'] ?? null)) {
            throw new InvalidArgumentException("Disk '{$name}': invalid endpoint");
        }
        $this->scheme = is_string($parts['scheme'] ?? null) ? $parts['scheme'] : 'https';
        $host = $parts['host'] . (is_int($parts['port'] ?? null) ? ':' . $parts['port'] : '');
        $this->pathStyle = $pathStyle === true;
        $this->host = $this->pathStyle ? $host : $this->bucket . '.' . $host;
        $root = is_string($config['root'] ?? null) ? Path::normalize($config['root'], allowEmpty: true) : '';
        $this->prefix = $root === '' ? '' : $root . '/';
        $this->io = new Filesystem();
    }

    /** @var array<string, mixed> */
    private readonly array $config;

    private readonly string $bucket;

    private readonly bool $pathStyle;

    public function exists(string $path): bool
    {
        $response = $this->request('HEAD', $this->key($path));
        if ($response->status() === 404) {
            return false;
        }
        $this->ensureOk($response, $path);

        return true;
    }

    public function get(string $path): string
    {
        $response = $this->request('GET', $this->key($path));
        $this->ensureOk($response, $path);

        return $response->body();
    }

    public function chunks(string $path): iterable
    {
        $temp = tempnam(sys_get_temp_dir(), 'omnitrix-s3-');
        if ($temp === false) {
            throw new FilesystemException('Could not create a temporary file');
        }
        try {
            $response = $this->request('GET', $this->key($path), downloadTo: $temp);
            $this->ensureOk($response, $path);
            yield from $this->io->readChunks($temp);
        } finally {
            @unlink($temp);
        }
    }

    public function put(string $path, string $contents, array $options = []): void
    {
        $headers = $this->writeHeaders($path, $options, MimeTypes::detectBuffer($contents));
        $this->ensureOk($this->request('PUT', $this->key($path), [], $headers, $contents), $path);
    }

    public function putFileAs(string $path, string|UploadedFile $file, array $options = []): void
    {
        $source = $file instanceof UploadedFile ? $file->path() : $file;
        if (!is_file($source)) {
            throw new FilesystemException("No such file to store: {$source}");
        }
        $size = (int) @filesize($source);
        if ($size <= $this->intConfig('multipart_threshold', 16 * 1024 * 1024)) {
            $this->put($path, $this->io->read($source), $options + ['content_type' => MimeTypes::detect($source) ?? 'application/octet-stream']);

            return;
        }
        $this->multipart($path, $source, $options + ['content_type' => MimeTypes::detect($source) ?? 'application/octet-stream']);
    }

    public function delete(string|array $paths): bool
    {
        foreach ((array) $paths as $path) {
            $response = $this->request('DELETE', $this->key($path));
            if ($response->status() !== 404) {
                $this->ensureOk($response, $path);
            }
        }

        return true;
    }

    public function copy(string $from, string $to): void
    {
        $source = '/' . $this->bucket . '/' . Signer::encodePath($this->key($from));
        $response = $this->request('PUT', $this->key($to), [], ['x-amz-copy-source' => $source]);
        $this->ensureOk($response, $from);
        if (str_contains($response->body(), '<Error>')) { // CopyObject can fail with a 200
            throw $this->failure($response, $from);
        }
    }

    public function move(string $from, string $to): void
    {
        $this->copy($from, $to);
        $this->delete($from);
    }

    public function size(string $path): int
    {
        return (int) ($this->head($path)->header('content-length') ?? '0');
    }

    public function lastModified(string $path): int
    {
        $time = strtotime($this->head($path)->header('last-modified') ?? '');

        return $time === false ? 0 : $time;
    }

    public function mimeType(string $path): ?string
    {
        $type = $this->head($path)->header('content-type');

        return $type === null || $type === '' ? null : trim(explode(';', $type)[0]);
    }

    public function files(string $directory = '', bool $recursive = false): array
    {
        $directory = Path::normalize($directory, allowEmpty: true);
        $prefix = $this->prefix . ($directory === '' ? '' : $directory . '/');
        $files = [];
        $token = null;
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix];
            if (!$recursive) {
                $query['delimiter'] = '/';
            }
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            $response = $this->request('GET', '', $query);
            $this->ensureOk($response, $directory);
            $xml = self::xml($response->body());
            foreach ($xml->Contents ?? [] as $object) {
                $key = (string) $object->Key;
                if ($key !== '' && !str_ends_with($key, '/')) {
                    $files[] = substr($key, strlen($this->prefix));
                }
            }
            $token = (string) ($xml->IsTruncated ?? '') === 'true' ? (string) $xml->NextContinuationToken : null;
        } while ($token !== null && $token !== '');
        sort($files);

        return $files;
    }

    public function deleteDirectory(string $directory): void
    {
        $this->delete($this->files(Path::normalize($directory), recursive: true));
    }

    public function url(string $path): string
    {
        $base = is_string($this->config['url'] ?? null) && $this->config['url'] !== '' ? rtrim($this->config['url'], '/') : null;
        if ($base !== null) {
            return $base . '/' . Signer::encodePath(Path::normalize($path));
        }

        return $this->scheme . '://' . $this->host . $this->objectPath($this->key($path));
    }

    /** @param array<string, string> $query extra signed parameters (response-content-disposition, …) */
    public function temporaryUrl(string $path, int|DateTimeInterface $expiration, array $query = []): string
    {
        $seconds = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() - time() : $expiration;
        $seconds = max(1, min(604800, $seconds)); // SigV4 allows at most 7 days
        $objectPath = $this->objectPath($this->key($path));

        return $this->scheme . '://' . $this->host . $objectPath . '?' . $this->signer->presign('GET', $objectPath, $this->host, $seconds, $query);
    }

    /** Redirects to a presigned URL valid for five minutes. */
    public function response(string $path, ?string $downloadName = null): Response
    {
        if (!$this->exists($path)) {
            throw FileNotFoundException::at($this->name, $path);
        }
        $disposition = ServesFiles::disposition($downloadName !== null, $downloadName ?? basename($path));

        return Response::redirect($this->temporaryUrl($path, 300, ['response-content-disposition' => $disposition]), 302);
    }

    /** @param array{content_type?: string, visibility?: 'public'|'private'} $options */
    private function multipart(string $path, string $source, array $options): void
    {
        $key = $this->key($path);
        $created = $this->request('POST', $key, ['uploads' => ''], $this->writeHeaders($path, $options, null));
        $this->ensureOk($created, $path);
        $uploadId = (string) self::xml($created->body())->UploadId;
        if ($uploadId === '') {
            throw new FilesystemException("S3 did not start a multipart upload for {$path}");
        }
        $partSize = max(5 * 1024 * 1024, $this->intConfig('part_size', 16 * 1024 * 1024)); // S3 minimum: 5 MiB
        $handle = @fopen($source, 'rb');
        if ($handle === false) {
            throw new FilesystemException("Could not read {$source}");
        }
        try {
            $parts = '';
            $number = 0;
            while (!feof($handle)) {
                $chunk = '';
                while (strlen($chunk) < $partSize && !feof($handle)) {
                    $read = fread($handle, max(1, $partSize - strlen($chunk)));
                    if ($read === false) {
                        throw new FilesystemException("Read error on {$source}");
                    }
                    $chunk .= $read;
                }
                if ($chunk === '' && $number > 0) {
                    break;
                }
                $number++;
                $part = $this->request('PUT', $key, ['partNumber' => (string) $number, 'uploadId' => $uploadId], [], $chunk);
                $this->ensureOk($part, $path);
                $etag = $part->header('etag') ?? '';
                $parts .= sprintf('<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>', $number, htmlspecialchars($etag, ENT_XML1));
            }
            $done = $this->request('POST', $key, ['uploadId' => $uploadId], ['content-type' => 'application/xml'], '<CompleteMultipartUpload>' . $parts . '</CompleteMultipartUpload>');
            $this->ensureOk($done, $path);
            if (str_contains($done->body(), '<Error>')) {
                throw $this->failure($done, $path);
            }
        } catch (Throwable $e) {
            try {
                $this->request('DELETE', $key, ['uploadId' => $uploadId]);
            } catch (Throwable) {
                // the bucket's lifecycle rules clean up abandoned parts
            }
            throw $e;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param array{content_type?: string, visibility?: 'public'|'private'} $options
     *
     * @return array<string, string>
     */
    private function writeHeaders(string $path, array $options, ?string $detected): array
    {
        $headers = ['content-type' => $options['content_type'] ?? $detected ?? MimeTypes::forPath($path)];
        $visibility = $options['visibility'] ?? (is_string($this->config['visibility'] ?? null) ? $this->config['visibility'] : null);
        if ($visibility !== null) {
            $headers['x-amz-acl'] = Path::visibility($visibility) === 'public' ? 'public-read' : 'private';
        }

        return $headers;
    }

    private function head(string $path): HttpResponse
    {
        $response = $this->request('HEAD', $this->key($path));
        $this->ensureOk($response, $path);

        return $response;
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    private function request(string $method, string $key, array $query = [], array $headers = [], string $body = '', ?string $downloadTo = null): HttpResponse
    {
        $objectPath = $key === '' && $this->pathStyle ? '/' . rawurlencode($this->bucket) . '/' : ($key === '' ? '/' : $this->objectPath($key));
        $headers['host'] = $this->host;
        $signed = $this->signer->sign($method, $objectPath, $query, $headers, hash('sha256', $body));
        unset($headers['host']);
        $queryString = Signer::query($query);
        $uri = Uri::parse($this->scheme . '://' . $this->host . $objectPath . ($queryString === '' ? '' : '?' . $queryString));
        $send = [];
        foreach ($headers + $signed as $name => $value) {
            $send[self::canonical($name)] = $value;
        }
        if ($body !== '' || in_array($method, ['PUT', 'POST'], true)) {
            $send['Content-Length'] = (string) strlen($body);
        }
        try {
            return $this->http->send($method, $uri, $send, $body, new RequestOptions(maxRedirects: 0, downloadTo: $downloadTo, maxResponseSize: $downloadTo === null ? null : PHP_INT_MAX));
        } catch (Throwable $e) {
            throw new FilesystemException("S3 request failed ({$method} {$key}): " . $e->getMessage(), 0, $e);
        }
    }

    private function objectPath(string $key): string
    {
        return ($this->pathStyle ? '/' . rawurlencode($this->bucket) : '') . '/' . Signer::encodePath($key);
    }

    private function key(string $path): string
    {
        return $this->prefix . Path::normalize($path);
    }

    private function ensureOk(HttpResponse $response, string $path): void
    {
        if ($response->status() === 404) {
            throw FileNotFoundException::at($this->name, $path);
        }
        if ($response->status() < 200 || $response->status() >= 300) {
            throw $this->failure($response, $path);
        }
    }

    private function failure(HttpResponse $response, string $path): FilesystemException
    {
        $code = $message = '';
        if (str_starts_with(trim($response->body()), '<')) {
            $xml = @simplexml_load_string($response->body());
            if ($xml instanceof SimpleXMLElement) {
                $code = (string) $xml->Code;
                $message = (string) $xml->Message;
            }
        }

        return new FilesystemException(sprintf("S3 error on disk '%s' for %s: HTTP %d%s%s", $this->name, $path, $response->status(), $code === '' ? '' : " {$code}", $message === '' ? '' : " ({$message})"));
    }

    private static function xml(string $body): SimpleXMLElement
    {
        $xml = @simplexml_load_string($body);
        if (!$xml instanceof SimpleXMLElement) {
            throw new FilesystemException('Unreadable S3 response');
        }

        return $xml;
    }

    private function intConfig(string $name, int $default): int
    {
        $value = $this->config[$name] ?? null;

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private static function canonical(string $name): string
    {
        return implode('-', array_map(ucfirst(...), explode('-', strtolower($name))));
    }
}
