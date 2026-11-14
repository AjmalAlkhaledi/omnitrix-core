<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient\Transport;

use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Core\HttpClient\Uri;
use Omnitrix\Core\Pool\ClientPool;
use Omnitrix\Core\Pool\PoolTimeoutException;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client as Http1Client;
use OpenSwoole\Coroutine\Http2\Client as Http2Client;
use OpenSwoole\Http2\Request as Http2Request;
use OpenSwoole\Http2\Response as Http2Response;
use Throwable;

use function filter_var;
use function is_array;
use function is_int;
use function is_string;
use function max;
use function strtolower;

use const FILTER_VALIDATE_IP;

/**
 * Pooled OpenSwoole coroutine HTTP transport.
 *
 * HTTP/1.1 exchanges use `Coroutine\Http\Client` (keep-alive, transparent reconnect, body
 * decompression); HTTP/2 exchanges use `Coroutine\Http2\Client` with one stream per leased
 * connection. Both are pooled per origin + TLS settings. TLS verification is always requested
 * explicitly because the extension's default is *not* to verify.
 */
final class OpenSwooleTransport implements TransportInterface
{
    /** @var array<string, ClientPool<Http1Client>> */
    private array $http1 = [];

    /** @var array<string, ClientPool<Http2Client>> */
    private array $http2 = [];

    private ?int $evictionTimer = null;

    public function __construct(private readonly HttpClientConfig $config) {}

    public function exchange(Exchange $exchange, ?CancellationToken $token = null): RawResponse
    {
        return $exchange->http2 ? $this->exchangeHttp2($exchange, $token) : $this->exchangeHttp1($exchange, $token);
    }

    private function exchangeHttp1(Exchange $x, ?CancellationToken $token): RawResponse
    {
        $pool = $this->http1Pool($x);
        try {
            $client = $pool->acquire($token);
        } catch (PoolTimeoutException $e) {
            throw new TimeoutException($e->getMessage(), 0, $e);
        }
        $healthy = true;
        $cid = Coroutine::getCid();
        $token?->attach($cid);
        try {
            $client->set([
                'connect_timeout' => $x->connectTimeout,
                'read_timeout' => $x->timeout,
                'write_timeout' => $x->timeout,
                'timeout' => $x->timeout,
                'keep_alive' => true,
                'ssl_verify_peer' => $x->verifyTls,
                'ssl_allow_self_signed' => false,
            ] + self::sniOptions($x->uri) + ($x->caFile !== null ? ['ssl_cafile' => $x->caFile] : []));
            $client->setMethod($x->method);
            $client->setHeaders($x->headers);
            $client->setData($x->body);

            $ok = $x->downloadTo !== null ? $client->download($x->uri->target(), $x->downloadTo) : $client->execute($x->uri->target());
            $status = is_int($client->statusCode) ? $client->statusCode : -1;
            if ($ok !== true || $status < 0) {
                $healthy = false;
                $errCode = is_int($client->errCode) ? $client->errCode : 0;
                $errMsg = is_string($client->errMsg) ? $client->errMsg : '';
                throw ErrorMapper::map($errCode, $errMsg, $status, $x->uri, $status === -1 ? 'connect' : 'response');
            }
            $headers = [];
            if (is_array($client->headers)) {
                foreach ($client->headers as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[strtolower($name)] = $value;
                    }
                }
            }
            $cookies = [];
            if (is_array($client->set_cookie_headers)) {
                foreach ($client->set_cookie_headers as $cookie) {
                    if (is_string($cookie)) {
                        $cookies[] = $cookie;
                    }
                }
            }
            $body = is_string($client->body) ? $client->body : '';
            $connection = $headers['connection'] ?? '';
            if (strtolower($connection) === 'close') {
                $healthy = false; // the server will close; do not hand a dead socket to the next user
            }

            return new RawResponse($status, $headers, $body, $cookies, '1.1');
        } catch (Throwable $e) {
            $healthy = false;
            throw $e;
        } finally {
            $token?->detach($cid);
            $pool->release($client, $healthy && !Coroutine::isCanceled());
        }
    }

    private function exchangeHttp2(Exchange $x, ?CancellationToken $token): RawResponse
    {
        $pool = $this->http2Pool($x);
        try {
            $client = $pool->acquire($token);
        } catch (PoolTimeoutException $e) {
            throw new TimeoutException($e->getMessage(), 0, $e);
        }
        $healthy = true;
        $cid = Coroutine::getCid();
        $token?->attach($cid);
        try {
            if ($client->connected !== true && $client->connect() !== true) {
                $healthy = false;
                throw ErrorMapper::map(is_int($client->errCode) ? $client->errCode : 0, is_string($client->errMsg) ? $client->errMsg : '', -1, $x->uri, 'connect');
            }
            $request = new Http2Request();
            $request->method = $x->method;
            $request->path = $x->uri->target();
            $request->headers = $x->headers;
            if ($x->body !== '') {
                $request->data = $x->body;
            }
            $streamId = $client->send($request);
            if ($streamId === false) {
                $healthy = false;
                throw ErrorMapper::map(is_int($client->errCode) ? $client->errCode : 0, is_string($client->errMsg) ? $client->errMsg : '', -3, $x->uri, 'send');
            }
            $response = $client->recv($x->timeout);
            if (!$response instanceof Http2Response) {
                $healthy = false;
                throw ErrorMapper::map(is_int($client->errCode) ? $client->errCode : 0, is_string($client->errMsg) ? $client->errMsg : '', -2, $x->uri, 'response');
            }
            $headers = [];
            if (is_array($response->headers)) {
                foreach ($response->headers as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[strtolower($name)] = $value;
                    }
                }
            }
            $cookies = [];
            if (is_array($response->set_cookie_headers)) {
                foreach ($response->set_cookie_headers as $cookie) {
                    if (is_string($cookie)) {
                        $cookies[] = $cookie;
                    }
                }
            }

            return new RawResponse(is_int($response->statusCode) ? $response->statusCode : 0, $headers, is_string($response->data) ? $response->data : '', $cookies, '2');
        } catch (Throwable $e) {
            $healthy = false;
            throw $e;
        } finally {
            $token?->detach($cid);
            $pool->release($client, $healthy && !Coroutine::isCanceled());
        }
    }

    /** @return ClientPool<Http1Client> */
    private function http1Pool(Exchange $x): ClientPool
    {
        $key = self::poolKey($x->uri, $x->verifyTls, $x->caFile) . '#h1';
        if (!isset($this->http1[$key])) {
            $uri = $x->uri;
            $this->http1[$key] = new ClientPool(
                $key,
                $this->config->poolMaxPerHost,
                $this->config->poolAcquireTimeout,
                $this->config->poolIdleTimeout,
                static fn(): Http1Client => new Http1Client($uri->host, $uri->port, $uri->isTls()),
                static function (Http1Client $client): void {
                    $client->close();
                },
            );
            $this->startEviction();
        }

        return $this->http1[$key];
    }

    /** @return ClientPool<Http2Client> */
    private function http2Pool(Exchange $x): ClientPool
    {
        $key = self::poolKey($x->uri, $x->verifyTls, $x->caFile) . '#h2';
        if (!isset($this->http2[$key])) {
            $uri = $x->uri;
            $verify = $x->verifyTls;
            $caFile = $x->caFile;
            $this->http2[$key] = new ClientPool(
                $key,
                $this->config->poolMaxPerHost,
                $this->config->poolAcquireTimeout,
                $this->config->poolIdleTimeout,
                static function () use ($uri, $verify, $caFile, $x): Http2Client {
                    $client = new Http2Client($uri->host, $uri->port, $uri->isTls());
                    $client->set(['timeout' => $x->timeout, 'ssl_verify_peer' => $verify] + self::sniOptions($uri) + ($caFile !== null ? ['ssl_cafile' => $caFile] : []));

                    return $client;
                },
                static function (Http2Client $client): void {
                    $client->close();
                },
            );
            $this->startEviction();
        }

        return $this->http2[$key];
    }

    /**
     * SNI / hostname verification only applies to DNS names; for IP literals OpenSSL checks the
     * certificate's IP SANs against the connected address instead.
     *
     * @return array<string, string>
     */
    private static function sniOptions(Uri $uri): array
    {
        return filter_var($uri->host, FILTER_VALIDATE_IP) === false ? ['ssl_host_name' => $uri->host] : [];
    }

    private static function poolKey(Uri $uri, bool $verify, ?string $caFile): string
    {
        return $uri->origin() . ($verify ? '' : '#noverify') . ($caFile !== null ? '#ca=' . $caFile : '');
    }

    private function startEviction(): void
    {
        if ($this->evictionTimer !== null || $this->config->poolIdleTimeout <= 0.0) {
            return;
        }
        $this->evictionTimer = Timers::tick(max(1000, (int) ($this->config->poolIdleTimeout * 500)), function (): void {
            foreach ($this->http1 as $pool) {
                $pool->evict();
            }
            foreach ($this->http2 as $pool) {
                $pool->evict();
            }
        });
    }

    public function stats(): array
    {
        $pools = [];
        foreach ($this->http1 as $key => $pool) {
            $pools[$key] = $pool->stats();
        }
        foreach ($this->http2 as $key => $pool) {
            $pools[$key] = $pool->stats();
        }

        return ['pools' => $pools];
    }

    public function close(): void
    {
        if ($this->evictionTimer !== null) {
            Timers::clear($this->evictionTimer);
            $this->evictionTimer = null;
        }
        foreach ($this->http1 as $pool) {
            $pool->close();
        }
        foreach ($this->http2 as $pool) {
            $pool->close();
        }
    }
}
