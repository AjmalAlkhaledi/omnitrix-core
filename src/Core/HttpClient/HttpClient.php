<?php

declare(strict_types=1);

namespace Omnitrix\Core\HttpClient;

use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\Semaphore;
use Omnitrix\Core\HttpClient\Exception\ConnectionException;
use Omnitrix\Core\HttpClient\Exception\DnsException;
use Omnitrix\Core\HttpClient\Exception\HttpClientException;
use Omnitrix\Core\HttpClient\Exception\ResponseTooLargeException;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\Exception\TooManyRedirectsException;
use Omnitrix\Core\HttpClient\Transport\Exchange;
use Omnitrix\Core\HttpClient\Transport\OpenSwooleTransport;
use Omnitrix\Core\HttpClient\Transport\RawResponse;
use Omnitrix\Core\HttpClient\Transport\TransportInterface;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Coroutine\Sleep;

use function hrtime;
use function in_array;
use function max;
use function min;
use function random_int;
use function strlen;

/**
 * Outbound HTTP client: pooled connections, strict TLS, timeouts, redirect policy, bounded
 * response size, idempotent-only retries and a global in-flight cap. One instance per worker;
 * requests are built with {@see request()}.
 */
final class HttpClient
{
    private const array IDEMPOTENT = ['GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE'];

    private const array REDIRECTS = [301, 302, 303, 307, 308];

    private readonly TransportInterface $transport;

    private readonly Semaphore $inFlight;

    /** @var array<string, int> */
    private array $counters = ['requests' => 0, 'responses' => 0, 'redirects' => 0, 'retries' => 0, 'failures' => 0, 'too_large' => 0];

    public function __construct(public readonly HttpClientConfig $config, ?TransportInterface $transport = null)
    {
        $this->transport = $transport ?? new OpenSwooleTransport($config);
        $this->inFlight = new Semaphore($config->maxConcurrent);
    }

    public function request(): PendingRequest
    {
        return new PendingRequest($this);
    }

    /** @param array<string, scalar|null|array<mixed>> $query */
    public function get(string $url, array $query = []): Response
    {
        return $this->request()->withQuery($query)->get($url);
    }

    /** @param array<string, mixed>|null $json */
    public function post(string $url, ?array $json = null): Response
    {
        $request = $this->request();

        return ($json === null ? $request : $request->asJson($json))->post($url);
    }

    /**
     * Executes a prepared request: redirects, retries, size limit and in-flight cap.
     *
     * @param array<string, string> $headers canonical names
     */
    public function send(string $method, Uri $uri, array $headers, string $body, RequestOptions $options, ?CancellationToken $token = null): Response
    {
        $started = hrtime(true);
        $this->counters['requests']++;
        $timeout = $options->timeout ?? $this->config->timeout;
        $remaining = ContextStorage::current()?->remainingTime();
        if ($remaining !== null) {
            $timeout = max(0.001, min($timeout, $remaining));
        }

        $this->inFlight->acquire($options->timeout ?? $this->config->timeout, $token);
        try {
            $current = $uri;
            $currentMethod = $method;
            $currentBody = $body;
            $currentHeaders = $headers;
            $redirects = 0;
            $maxRedirects = $options->maxRedirects ?? $this->config->maxRedirects;

            while (true) {
                $exchange = new Exchange($currentMethod, $current, $currentHeaders + ['Host' => $current->hostHeader()], $currentBody, $options->connectTimeout ?? $this->config->connectTimeout, $timeout, $options->verifyTls ?? $this->config->verifyTls, $options->caFile ?? $this->config->caFile, $options->http2 ?? $this->config->http2, $options->downloadTo);
                $raw = $this->exchangeWithRetries($exchange, $options, $token);

                if ($maxRedirects > 0 && in_array($raw->status, self::REDIRECTS, true) && isset($raw->headers['location'])) {
                    if (++$redirects > $maxRedirects) {
                        $this->counters['failures']++;
                        throw new TooManyRedirectsException("More than {$maxRedirects} redirects starting from {$uri}");
                    }
                    $this->counters['redirects']++;
                    $next = $current->resolve($raw->headers['location']);
                    if ($raw->status === 303 || (($raw->status === 301 || $raw->status === 302) && $currentMethod !== 'GET' && $currentMethod !== 'HEAD')) {
                        $currentMethod = 'GET';
                        $currentBody = '';
                        unset($currentHeaders['Content-Type'], $currentHeaders['Content-Length']);
                    }
                    if ($next->host !== $current->host || $next->port !== $current->port || $next->scheme !== $current->scheme) {
                        unset($currentHeaders['Authorization'], $currentHeaders['Cookie']); // never leak credentials across origins
                    }
                    unset($currentHeaders['Host']);
                    $current = $next;
                    continue;
                }

                $limit = $options->maxResponseSize ?? $this->config->maxResponseSize;
                if (strlen($raw->body) > $limit) {
                    $this->counters['too_large']++;
                    $this->counters['failures']++;
                    throw new ResponseTooLargeException("Response from {$current} is " . strlen($raw->body) . " bytes, limit {$limit} (use download() for large bodies)");
                }
                $this->counters['responses']++;

                return new Response($raw->status, $raw->headers, $raw->body, (string) $current, $raw->setCookies, $redirects, (hrtime(true) - $started) / 1e9, $raw->protocol);
            }
        } finally {
            $this->inFlight->release();
        }
    }

    private function exchangeWithRetries(Exchange $exchange, RequestOptions $options, ?CancellationToken $token): RawResponse
    {
        $retries = $options->retries ?? $this->config->retries;
        $idempotent = in_array($exchange->method, self::IDEMPOTENT, true);
        $attempt = 0;
        while (true) {
            try {
                return $this->transport->exchange($exchange, $token);
            } catch (ConnectionException|DnsException|TimeoutException $e) {
                $retryable = $idempotent && $attempt < $retries && ($e instanceof ConnectionException || ($e instanceof TimeoutException && ($options->retryOnTimeout ?? $this->config->retryOnTimeout)));
                if (!$retryable || ($token !== null && $token->isCancelled())) {
                    $this->counters['failures']++;
                    throw $e;
                }
                $attempt++;
                $this->counters['retries']++;
                $delay = $this->config->retryBaseDelayMs * (2 ** ($attempt - 1));
                Sleep::milliseconds(min(2000, $delay) + ($delay > 0 ? random_int(0, (int) ($delay / 2)) : 0));
            } catch (HttpClientException $e) {
                $this->counters['failures']++;
                throw $e;
            }
        }
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->counters + ['in_flight' => $this->inFlight->inUse(), 'in_flight_peak' => $this->inFlight->peak(), 'transport' => $this->transport->stats()];
    }

    public function close(): void
    {
        $this->transport->close();
    }
}
