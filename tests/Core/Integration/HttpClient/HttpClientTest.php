<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\HttpClient;

use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\TimeoutException as AsyncTimeout;
use Omnitrix\Core\HttpClient\Exception\ConnectionException;
use Omnitrix\Core\HttpClient\Exception\DnsException;
use Omnitrix\Core\HttpClient\Exception\HttpClientException;
use Omnitrix\Core\HttpClient\Exception\ResponseTooLargeException;
use Omnitrix\Core\HttpClient\Exception\TimeoutException;
use Omnitrix\Core\HttpClient\Exception\TlsException;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;

use function dirname;
use function file_exists;
use function filesize;
use function hrtime;
use function md5;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;

/** Outbound HTTP against the controlled local upstream (HTTP and HTTPS with a private CA). */
#[CoversNothing]
final class HttpClientTest extends TestCase
{
    private const int HTTP_PORT = 18600;

    private const int HTTPS_PORT = 18601;

    private static ?ProcessRunner $http = null;

    private static ?ProcessRunner $https = null;

    private static string $certDir = '';

    private ?HttpClient $client = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4);
        self::$certDir = $root . '/var/tls';
        $certs = ProcessRunner::command([$root . '/tests/Core/Fixtures/tls/make-certs.sh', self::$certDir]);
        if ($certs->wait(60.0) !== 0) {
            self::fail('certificate generation failed: ' . $certs->stderr());
        }
        self::$http = new ProcessRunner($root . '/tests/Core/Fixtures/upstream_server.php', [(string) self::HTTP_PORT, 'http']);
        self::$https = new ProcessRunner($root . '/tests/Core/Fixtures/upstream_server.php', [(string) self::HTTPS_PORT, 'https', self::$certDir]);
        self::$http->waitForPort('127.0.0.1', self::HTTP_PORT, 15.0);
        self::$https->waitForPort('127.0.0.1', self::HTTPS_PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$http?->stop(5.0);
        self::$https?->stop(5.0);
        self::$http = self::$https = null;
    }

    /** @param array<string, mixed> $config */
    private function client(array $config = []): HttpClient
    {
        $this->client = new HttpClient(HttpClientConfig::fromArray($config + ['timeout' => 5.0, 'connect_timeout' => 2.0, 'retry_base_delay_ms' => 10]));

        return $this->client;
    }

    protected function tearDown(): void
    {
        $this->client?->close();
        $this->client = null;
        parent::tearDown();
    }

    private static function url(string $path, bool $tls = false): string
    {
        return ($tls ? 'https://localhost:' . self::HTTPS_PORT : 'http://127.0.0.1:' . self::HTTP_PORT) . $path;
    }

    public function testVerbsHeadersQueryJsonAndFormBodiesRoundTrip(): void
    {
        $client = $this->client();
        $r = $client->request()->withHeaders(['X-Probe' => 'p'])->withToken('tok')->withQuery(['a' => '1'])->asJson(['k' => 'v'])->post(self::url('/echo?z=9'));
        self::assertTrue($r->ok());
        $echo = $r->jsonObject();
        self::assertSame('POST', $echo['method']);
        self::assertSame(['z' => '9', 'a' => '1'], $echo['query']);
        self::assertSame('{"k":"v"}', $echo['body']);
        $headers = self::assoc($echo['headers']);
        self::assertSame('p', $headers['x-probe']);
        self::assertSame('Bearer tok', $headers['authorization']);
        self::assertSame('application/json', $headers['content-type']);
        self::assertSame('Omnitrix/0.1', $headers['user-agent']);

        self::assertSame('a=1&b=x%20y', self::assoc($client->request()->asForm(['a' => 1, 'b' => 'x y'])->put(self::url('/echo'))->jsonObject())['body']);
        foreach (['PATCH', 'DELETE'] as $method) {
            self::assertSame($method, self::assoc($client->request()->send($method, self::url('/echo'))->jsonObject())['method']);
        }
        $head = $client->request()->head(self::url('/echo'));
        self::assertSame(200, $head->status());
        self::assertSame('', $head->body());
        self::assertSame(418, $client->get(self::url('/status/418'))->status());
        self::assertSame(['a=1', 'b=2'], array_map(static fn(string $c): string => explode(';', $c)[0], $client->get(self::url('/cookies'))->setCookies()));
    }

    public function testConcurrentSlowRequestsOverlapAndReuseConnections(): void
    {
        $client = $this->client(['pool' => ['max_per_host' => 10]]);
        $before = self::int($client->get(self::url('/stats'))->jsonObject()['connections_accepted']);
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[$i] = static fn(): int => $client->get(self::url('/slow?ms=400'))->status();
        }
        [$statuses, $elapsed] = self::runConcurrently($tasks);
        self::assertSame(array_fill(0, 10, 200), array_values($statuses));
        self::assertLessThan(1.0, $elapsed, "10 × 400 ms took {$elapsed}s; they must overlap");

        for ($i = 0; $i < 30; $i++) {
            $client->get(self::url('/echo'));
        }
        $after = self::int($client->get(self::url('/stats'))->jsonObject()['connections_accepted']);
        self::assertLessThanOrEqual(10, $after - $before, '30 sequential requests reuse the pooled connections');
        $pools = self::assoc(self::assoc($client->stats()['transport'])['pools']);
        self::assertCount(1, $pools);
    }

    public function testTimeoutsAreEnforcedPerPhase(): void
    {
        $client = $this->client();
        $t = hrtime(true);
        try {
            $client->request()->timeout(0.3)->get(self::url('/slow?ms=2000'));
            self::fail('expected read timeout');
        } catch (TimeoutException $e) {
            self::assertStringContainsString('response', $e->getMessage());
        }
        self::assertLessThan(1.0, (hrtime(true) - $t) / 1e9);

        $t = hrtime(true);
        try {
            $client->request()->connectTimeout(0.4)->get('http://10.255.255.1/');
            self::fail('expected connect timeout');
        } catch (TimeoutException $e) {
            self::assertStringContainsString('connect', $e->getMessage());
        }
        self::assertLessThan(1.5, (hrtime(true) - $t) / 1e9);
        self::assertSame(200, $client->get(self::url('/echo'))->status(), 'client healthy after timeouts');
    }

    public function testConnectionAndDnsFailuresAreTyped(): void
    {
        $client = $this->client();
        try {
            $client->get('http://127.0.0.1:18699/');
            self::fail('refused');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }
        try {
            $client->get(self::url('/drop'));
            self::fail('server dropped the connection');
        } catch (ConnectionException $e) {
            self::assertStringContainsString('closed by the peer', $e->getMessage());
        }
        try {
            $client->get(self::url('/half'));
            self::fail('partial body');
        } catch (ConnectionException) {
        }
        try {
            $client->get('http://nonexistent.invalid/');
            self::fail('dns');
        } catch (DnsException $e) {
            self::assertStringContainsString('nonexistent.invalid', $e->getMessage());
        }
        self::assertSame('closing', $client->get(self::url('/close'))->body());
        self::assertSame(200, $client->get(self::url('/echo'))->status(), 'a Connection: close response does not poison the pool');
    }

    public function testTlsVerificationIsOnByDefaultAndPrivateCasWork(): void
    {
        $client = $this->client();
        try {
            $client->get(self::url('/echo', true));
            self::fail('untrusted CA must be rejected by default');
        } catch (TlsException $e) {
            self::assertStringContainsString('verify', $e->getMessage());
        }
        $trusted = $client->request()->withCaFile(self::$certDir . '/ca.crt')->get(self::url('/echo', true));
        self::assertSame(200, $trusted->status());
        self::assertSame(200, $client->request()->withCaFile(self::$certDir . '/ca.crt')->get('https://127.0.0.1:' . self::HTTPS_PORT . '/echo')->status(), 'IP literal hosts verify against the certificate IP SAN');
        self::assertSame(200, $client->request()->withoutTlsVerification()->get(self::url('/echo', true))->status(), 'explicit opt-out works');
        $pinned = $this->client(['ca_file' => self::$certDir . '/ca.crt']);
        self::assertSame(200, $pinned->get(self::url('/echo', true))->status(), 'ca_file in config');
    }

    public function testRedirectsRetriesLargeBodiesAndInvalidJson(): void
    {
        $client = $this->client(['retries' => 2]);
        $r = $client->get(self::url('/redirect?n=3'));
        self::assertSame(200, $r->status());
        self::assertSame(3, $r->redirectCount());
        self::assertSame(['from' => 'redirect'], self::assoc($r->jsonObject())['query']);
        self::assertSame('GET', self::assoc($client->request()->asJson(['a' => 1])->post(self::url('/redirect-303'))->jsonObject())['method']);

        $recovered = $client->get(self::url('/flaky?key=' . uniqid()));
        self::assertStringStartsWith('recovered after 2', $recovered->body(), 'GET retried after the first connection was dropped');
        try {
            $client->request()->asJson([])->post(self::url('/flaky?key=' . uniqid()));
            self::fail('POST must not be retried');
        } catch (ConnectionException) {
        }

        $big = $client->get(self::url('/large?bytes=5000000'));
        self::assertSame(5_000_000, $big->size());
        self::assertSame(md5(str_repeat('L', 5_000_000)), md5($big->body()));
        try {
            $client->request()->maxResponseSize(1_000_000)->get(self::url('/large?bytes=2000000'));
            self::fail('oversized');
        } catch (ResponseTooLargeException) {
        }
        $path = sys_get_temp_dir() . '/omnitrix-download-' . getmypid() . '.bin';
        try {
            $dl = $client->request()->download(self::url('/large?bytes=3000000'), $path);
            self::assertSame(200, $dl->status());
            self::assertSame('', $dl->body(), 'downloads are not buffered');
            self::assertTrue(file_exists($path));
            self::assertSame(3_000_000, filesize($path));
        } finally {
            @unlink($path);
        }
        try {
            $client->get(self::url('/json-invalid'))->json();
            self::fail('invalid JSON');
        } catch (HttpClientException $e) {
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
    }

    public function testHttp2PriorKnowledgeAndCancellation(): void
    {
        $client = $this->client();
        $r = $client->request()->http2()->asJson(['h' => 2])->post(self::url('/echo'));
        self::assertSame(200, $r->status());
        self::assertSame('2', $r->protocol());
        self::assertSame('HTTP/2', self::assoc($r->jsonObject())['protocol']);

        $t = hrtime(true);
        try {
            Async::withTimeout(static fn() => $client->get(self::url('/slow?ms=3000')), 0.3);
            self::fail('expected group timeout');
        } catch (AsyncTimeout) {
        }
        self::assertLessThan(1.0, (hrtime(true) - $t) / 1e9, 'cancellation interrupted the outbound wait');
        Sleep::milliseconds(50);
        self::assertSame(200, $client->get(self::url('/echo'))->status(), 'pool healthy after a cancelled exchange');
        self::assertSame(0, $client->stats()['in_flight']);
    }

    public function testPoolBackpressureAndGlobalConcurrencyCap(): void
    {
        $client = $this->client(['pool' => ['max_per_host' => 2, 'acquire_timeout' => 0.3], 'max_concurrent' => 3]);
        $tasks = [];
        for ($i = 0; $i < 3; $i++) {
            $tasks[] = static function () use ($client): string {
                try {
                    return (string) $client->get(self::url('/slow?ms=800'))->status();
                } catch (TimeoutException $e) {
                    return 'pool-timeout';
                }
            };
        }
        [$results] = self::runConcurrently($tasks);
        sort($results);
        self::assertSame(['200', '200', 'pool-timeout'], $results, 'the third request waits for a pooled connection and times out');
        self::assertSame(1, self::assoc(array_values(self::assoc(self::assoc($client->stats()['transport'])['pools']))[0])['timeouts']);
    }
}
