<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Http;

use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_filter;
use function array_map;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function hrtime;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function md5;
use function preg_match_all;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const OPENSWOOLE_SOCK_TCP;

/**
 * HTTP Server acceptance against the real OpenSwoole server, driven by OpenSwoole's own HTTP
 * client, a raw TCP client (malformed input, chunk timing, disconnects) and the curl binary.
 */
#[CoversNothing]
final class HttpServerTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const int PORT = 18330;

    private static ?ProcessRunner $server = null;

    private static string $markers = '';

    public static function setUpBeforeClass(): void
    {
        $markers = tempnam(sys_get_temp_dir(), 'omnitrix-http-markers-');
        if ($markers === false) {
            self::fail('cannot create markers file');
        }
        self::$markers = $markers;
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/http_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT,
            'OMNITRIX_FIXTURE_MARKERS' => self::$markers,
            'OMNITRIX_FIXTURE_TIMEOUT' => '2.5',
        ]);
        self::$server->waitForPort(self::HOST, self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(10.0);
        self::$server = null;
        if (self::$markers !== '' && file_exists(self::$markers)) {
            unlink(self::$markers);
        }
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>}
     */
    private function request(string $method, string $path, string $body = '', array $headers = [], float $timeout = 5.0): array
    {
        $client = new HttpClient(self::HOST, self::PORT);
        $client->set(['timeout' => $timeout]);
        $client->setMethod($method);
        if ($headers !== []) {
            $client->setHeaders($headers);
        }
        if ($body !== '') {
            $client->setData($body);
        }
        $client->execute($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $respBody = is_string($client->body) ? $client->body : '';
        $respHeaders = [];
        if (is_array($client->headers)) {
            foreach ($client->headers as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $respHeaders[$k] = $v;
                }
            }
        }
        $client->close();
        $decoded = json_decode($respBody, true);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $respBody, 'json' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    /** @return array<string, mixed> */
    private function stats(): array
    {
        return $this->request('GET', '/stats')['json'];
    }

    /** @return list<array<string, mixed>> */
    private static function markers(string $event): array
    {
        $raw = trim((string) file_get_contents(self::$markers));
        if ($raw === '') {
            return [];
        }
        $lines = array_map(static fn(string $l): array => self::decodeJsonObject($l), explode("\n", $raw));

        return array_values(array_filter($lines, static fn(array $m): bool => ($m['event'] ?? null) === $event));
    }

    public function testEchoExposesParsedRequestAndRequestIdHeader(): void
    {
        $r = $this->request('GET', '/echo?x=1&tags[]=a&tags[]=b', '', ['X-Probe' => 'yes', 'Cookie' => 's=1; t=2']);

        self::assertSame(200, $r['status']);
        self::assertSame('application/json', $r['headers']['content-type']);
        self::assertSame('GET', $r['json']['method']);
        self::assertSame('/echo', $r['json']['path']);
        self::assertSame(['x' => '1', 'tags' => ['a', 'b']], $r['json']['query']);
        self::assertSame('1.1', $r['json']['protocol']);
        $echoedHeaders = self::assoc($r['json']['headers']);
        self::assertSame('yes', $echoedHeaders['x-probe']);
        self::assertSame(self::HOST . ':' . self::PORT, $echoedHeaders['host']);
        self::assertArrayHasKey('user-agent', $echoedHeaders, 'absent headers are reported as null');
        self::assertSame(['s' => '1', 't' => '2'], $r['json']['cookies']);
        self::assertSame(self::HOST, $r['json']['remote']);
        self::assertSame($r['json']['request_id'], $r['headers']['x-request-id'], 'request id header matches the context id');
        self::assertStringContainsString('seen=1', $r['headers']['set-cookie']);
        self::assertSame(32, strlen(self::string($r['json']['request_id'])));
    }

    public function testJsonBodyRoundTripAndMalformedJsonIs400(): void
    {
        $ok = $this->request('POST', '/json', '{"name":"n","list":[1,2]}', ['Content-Type' => 'application/json']);
        self::assertSame(201, $ok['status']);
        self::assertSame(['name' => 'n', 'list' => [1, 2]], $ok['json']['received']);
        self::assertTrue($ok['json']['is_json']);

        $bad = $this->request('POST', '/json', '{"name":', ['Content-Type' => 'application/json']);
        self::assertSame(400, $bad['status']);
        $error = self::assoc($bad['json']['error']);
        self::assertSame(400, $error['status']);
        self::assertStringContainsString('Malformed JSON', self::string($error['message']));
        self::assertSame(32, strlen(self::string($bad['json']['request_id'])));
        self::assertStringNotContainsString('Stack', $bad['body']);
    }

    public function testBodyLimitsAt413BothAtTheAdapterAndAtTheParser(): void
    {
        $app = $this->request('POST', '/json', str_repeat('a', 70 * 1024), ['Content-Type' => 'application/json']);
        self::assertSame(413, $app['status'], 'adapter limit (64 KiB) answers a JSON 413');
        self::assertSame(65536, self::assoc($app['json']['error'])['max_body_size']);

        // Above package_max_length OpenSwoole answers 413 itself and closes; read it over raw TCP
        // because the coroutine HTTP client aborts when the server closes mid-upload.
        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $huge = str_repeat('a', 3 * 1024 * 1024);
        $tcp->send("POST /json HTTP/1.1\r\nHost: x\r\nContent-Type: application/json\r\nContent-Length: " . strlen($huge) . "\r\n\r\n");
        $tcp->send($huge);
        $reply = (string) $tcp->recv(3.0);
        $tcp->close();
        self::assertStringStartsWith('HTTP/1.1 413', $reply, 'OpenSwoole parser limit (package_max_length) answers 413 before the handler');

        self::assertSame(200, $this->request('GET', '/echo')['status'], 'server healthy after oversized bodies');
    }

    public function testStreamingDeliversChunksProgressively(): void
    {
        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $tcp->send("GET /stream?n=4&delay=150 HTTP/1.1\r\nHost: x\r\n\r\n");
        $start = hrtime(true);
        $received = '';
        $firstChunkAt = null;
        $lastAt = null;
        while (!str_contains($received, "0\r\n\r\n")) {
            $data = $tcp->recv(3.0);
            self::assertNotFalse($data, 'stream stalled');
            $received .= $data;
            if ($firstChunkAt === null && str_contains($received, 'chunk0')) {
                $firstChunkAt = (hrtime(true) - $start) / 1e9;
            }
            $lastAt = (hrtime(true) - $start) / 1e9;
        }
        $tcp->close();

        self::assertStringContainsString('Transfer-Encoding: chunked', $received);
        self::assertSame(4, preg_match_all('/^chunk\d/m', $received));
        Sleep::milliseconds(50);
        self::assertStringContainsString("end\n", $received, 'fixture markers: ' . json_encode(self::markers('stream.done')));
        self::assertNotNull($firstChunkAt);
        self::assertNotNull($lastAt);
        self::assertLessThan(0.3, $firstChunkAt, 'first chunk must arrive before the stream finishes (not buffered)');
        self::assertGreaterThan($firstChunkAt + 0.3, $lastAt, 'later chunks arrive later');
    }

    public function testClientDisconnectDuringStreamIsDetectedAndLeaksNothing(): void
    {
        $baseline = self::int($this->stats()['coroutine_num']);
        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $tcp->send("GET /stream?n=6&delay=100 HTTP/1.1\r\nHost: x\r\n\r\n");
        $first = $tcp->recv(2.0);
        self::assertIsString($first);
        $tcp->close();

        Sleep::milliseconds(1200);
        $marks = self::markers('stream.done');
        self::assertNotEmpty($marks, 'handler must finish after the client left');
        $last = $marks[count($marks) - 1];
        $writes = $last['writes'];
        self::assertIsArray($writes);
        self::assertTrue($last['client_gone'], 'write() must report the disconnected client');
        self::assertContains(false, $writes, 'at least one write reported failure');
        self::assertLessThanOrEqual($baseline, self::int($this->stats()['coroutine_num']), 'no coroutine left behind');
    }

    public function testKeepAliveReusesOneConnectionForManyRequests(): void
    {
        $before = self::int($this->stats()['connections_accepted']);
        $client = new HttpClient(self::HOST, self::PORT);
        $client->set(['timeout' => 5.0, 'keep_alive' => true]);
        for ($i = 0; $i < 50; $i++) {
            self::assertTrue($client->get('/echo?i=' . $i));
            self::assertSame(200, $client->statusCode);
        }
        $client->close();
        $after = self::int($this->stats()['connections_accepted']);
        // Each /stats probe counts its own connection, so: +1 keep-alive client, +1 for the second probe.
        self::assertSame(2, $after - $before, '50 requests must use a single connection');
    }

    public function testServerStaysResponsiveWhileManyRequestsWait(): void
    {
        $tasks = [];
        // 30 simultaneous new connections stay below small default listen backlogs (somaxconn);
        // beyond them SYN retransmits add 1 s and would measure the kernel, not the server.
        for ($i = 0; $i < 30; $i++) {
            $tasks['slow' . $i] = fn(): int => $this->request('GET', '/slow?ms=300', '', [], 10.0)['status'];
        }
        $tasks['fast'] = function (): float {
            Sleep::milliseconds(100); // the 50 slow requests are in flight now
            $t = hrtime(true);
            $status = $this->request('GET', '/echo')['status'];
            self::assertSame(200, $status);

            return (hrtime(true) - $t) / 1e9;
        };
        [$results, $elapsed] = self::runConcurrently($tasks);
        self::assertLessThan(0.1, $results['fast'], 'an unrelated request must not wait behind sleeping ones');
        // Serial execution would take 9 s; the bound leaves room for one SYN retransmit (1 s) that
        // a small kernel listen backlog occasionally causes under simultaneous connects.
        self::assertLessThan(2.5, $elapsed, '30 × 300 ms must overlap (serial would be 9 s)');
        unset($results['fast']);
        self::assertSame([200], array_values(array_unique($results)));
    }

    public function testMalformedRequestsDoNotHarmTheServer(): void
    {
        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $tcp->send("GARBAGE\r\n\r\n");
        $reply = $tcp->recv(1.0);
        $tcp->close();
        self::assertTrue($reply === false || $reply === '', 'malformed request line: connection closed without a response');

        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $tcp->send("GET / HTTP/1.1\r\nHost: x\r\nX-Big: " . str_repeat('h', 3 * 1024 * 1024) . "\r\n\r\n");
        $reply = (string) $tcp->recv(2.0);
        $tcp->close();
        self::assertStringStartsWith('HTTP/1.1 400', $reply, 'oversized header is rejected with 400');

        self::assertSame(200, $this->request('GET', '/echo')['status']);
    }

    public function testRequestTimeoutAnswers503WhileTheHandlerKeepsRunning(): void
    {
        $t = hrtime(true);
        $r = $this->request('GET', '/slow?ms=6000', '', [], 10.0);
        $elapsed = (hrtime(true) - $t) / 1e9;
        self::assertSame(503, $r['status']);
        self::assertSame('1', $r['headers']['retry-after']);
        self::assertSame('Request timed out', self::assoc($r['json']['error'])['message']);
        self::assertLessThan(4.0, $elapsed, 'timeout fires at 2.5 s');
        self::assertSame(200, $this->request('GET', '/echo')['status']);
    }

    public function testHeadRedirectFileAndLargeResponses(): void
    {
        $head = $this->request('HEAD', '/head');
        self::assertSame(200, $head['status']);
        self::assertSame('', $head['body']);
        self::assertSame('9', $head['headers']['content-length']);

        $redirect = $this->request('GET', '/redirect');
        self::assertSame(307, $redirect['status']);
        self::assertSame('/echo', $redirect['headers']['location']);

        $file = $this->request('GET', '/file');
        self::assertSame(200, $file['status']);
        self::assertSame(100_000, strlen($file['body']));
        self::assertSame(md5(str_repeat('F', 100_000)), md5($file['body']));

        $large = $this->request('GET', '/large?bytes=5000000', '', [], 20.0);
        self::assertSame(200, $large['status']);
        self::assertSame(5_000_000, strlen($large['body']));
        self::assertSame(md5(str_repeat('L', 5_000_000)), md5($large['body']));
    }

    public function testErrorMappingHidesInternalsAndCoversHandlerBugs(): void
    {
        $http = $this->request('GET', '/throw-http');
        self::assertSame(422, $http['status']);
        self::assertSame('validation', $http['headers']['x-error-kind']);
        self::assertSame(['status' => 422, 'message' => 'Validation failed', 'field' => 'email'], self::assoc($http['json']['error']));

        $internal = $this->request('GET', '/throw');
        self::assertSame(500, $internal['status']);
        self::assertStringNotContainsString('secret internal detail', $internal['body']);
        self::assertSame(32, strlen(self::string($internal['json']['request_id'])));

        $noend = $this->request('GET', '/noend');
        self::assertSame(500, $noend['status']);

        $missing = $this->request('GET', '/nope');
        self::assertSame(404, $missing['status']);
        self::assertSame(418, $this->request('GET', '/status?code=418')['status'], 'custom status codes pass through');
    }

    public function testCurlBinaryAgreesOnHeadersAndKeepAlive(): void
    {
        $curl = ProcessRunner::command(['/usr/bin/curl', '-s', '-D', '-', 'http://' . self::HOST . ':' . self::PORT . '/echo?via=curl']);
        $code = $curl->wait(10.0);
        $out = $curl->stdout();
        self::assertSame(0, $code, $curl->stderr());
        self::assertStringContainsString('HTTP/1.1 200 OK', $out);
        self::assertMatchesRegularExpression('/x-request-id: [0-9a-f]{32}/i', $out);
        self::assertStringContainsString('Connection: keep-alive', $out);
        self::assertStringContainsString('"via":"curl"', $out);
        self::assertStringNotContainsString('Server:', $out, 'no server identification header');
    }
}
