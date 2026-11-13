<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Http;

use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function dirname;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function str_contains;

use const OPENSWOOLE_SOCK_TCP;

/** Routing, groups, middleware ordering and response types over a real server. */
#[CoversNothing]
final class ApplicationRoutingTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const int PORT = 18340;

    private static ?ProcessRunner $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/app_server.php', [], ['OMNITRIX_FIXTURE_PORT' => (string) self::PORT]);
        self::$server->waitForPort(self::HOST, self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(10.0);
        self::$server = null;
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>}
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        $client = new HttpClient(self::HOST, self::PORT);
        $client->set(['timeout' => 5.0]);
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

    public function testHandlersReturnValuesMapToResponses(): void
    {
        $root = $this->request('GET', '/');
        self::assertSame(200, $root['status']);
        self::assertSame('application/json', $root['headers']['content-type']);
        self::assertSame(['hello' => 'world'], $root['json']);
        self::assertSame('global2;timing;', $root['headers']['x-order'], 'global middleware ran, innermost appends first');
        self::assertMatchesRegularExpression('/^\d+$/', $root['headers']['x-elapsed-us']);

        $text = $this->request('GET', '/text');
        self::assertSame('text/plain; charset=utf-8', $text['headers']['content-type']);
        self::assertSame('plain text', $text['body']);

        self::assertSame(204, $this->request('GET', '/empty')['status']);
        self::assertSame(['path' => 'a/b/c.json'], $this->request('GET', '/files/a/b/c.json')['json']);
    }

    public function testGroupsAndMiddlewareOrderingWithAuthentication(): void
    {
        $anon = $this->request('GET', '/api/users/5');
        self::assertSame(401, $anon['status']);
        self::assertSame('Bearer', $anon['headers']['www-authenticate']);

        $user = $this->request('GET', '/api/users/5', '', ['Authorization' => 'Bearer abc']);
        self::assertSame(200, $user['status']);
        self::assertSame(['id' => 5, 'route' => 'users.show', 'user' => 'user:abc'], $user['json']);
        self::assertSame('api;global2;timing;', $user['headers']['x-order'], 'inner (group) middleware appends first, outer (global) last');

        self::assertSame(404, $this->request('GET', '/api/users/abc', '', ['Authorization' => 'Bearer abc'])['status']);

        $forbidden = $this->request('DELETE', '/api/admin/users/5', '', ['Authorization' => 'Bearer abc']);
        self::assertSame(403, $forbidden['status']);
        self::assertSame('admin only', self::assoc($forbidden['json']['error'])['message']);

        self::assertSame(204, $this->request('DELETE', '/api/admin/users/5', '', ['Authorization' => 'Bearer admin-token'])['status']);

        $created = $this->request('POST', '/api/users', '{"name":"n"}', ['Authorization' => 'Bearer abc', 'Content-Type' => 'application/json']);
        self::assertSame(201, $created['status']);
        self::assertSame(['created' => ['name' => 'n']], $created['json']);
        self::assertSame('/api/users/1', $created['headers']['location']);
        self::assertStringContainsString('last_created=u', $created['headers']['set-cookie']);
    }

    public function testErrorsAreJsonWithCorrectStatusesAndNoInternals(): void
    {
        $notFound = $this->request('GET', '/missing');
        self::assertSame(404, $notFound['status']);
        self::assertSame(404, self::assoc($notFound['json']['error'])['status']);

        $notAllowed = $this->request('PUT', '/text');
        self::assertSame(405, $notAllowed['status']);
        self::assertSame('GET, HEAD', $notAllowed['headers']['allow']);

        $teapot = $this->request('GET', '/teapot');
        self::assertSame(418, $teapot['status']);
        self::assertSame('no', $teapot['headers']['x-brew']);
        self::assertSame("I'm a teapot", self::assoc($teapot['json']['error'])['message']);

        $boom = $this->request('GET', '/boom');
        self::assertSame(500, $boom['status']);
        self::assertStringNotContainsString('internal secret', $boom['body']);
        self::assertSame('global2;timing;', $boom['headers']['x-order'], 'errors become responses at the handler boundary, so middleware still wraps them');
        self::assertStringContainsString('internal secret', (string) self::$server?->stderr(), 'the real error is logged');

        $head = $this->request('HEAD', '/text');
        self::assertSame(200, $head['status']);
        self::assertSame('', $head['body']);
    }

    public function testStreamingResponsesOverTheRouter(): void
    {
        $tcp = new Client(OPENSWOOLE_SOCK_TCP);
        self::assertTrue($tcp->connect(self::HOST, self::PORT, 2.0));
        $tcp->send("GET /stream HTTP/1.1\r\nHost: x\r\n\r\n");
        $received = '';
        while (!str_contains($received, "0\r\n\r\n")) {
            $data = $tcp->recv(3.0);
            self::assertNotFalse($data);
            $received .= $data;
        }
        $tcp->close();
        self::assertStringContainsString('Content-Type: text/event-stream', $received);
        self::assertStringContainsString('Transfer-Encoding: chunked', $received);
        self::assertSame(3, substr_count($received, 'event: tick'));

        $chunks = $this->request('GET', '/chunks');
        self::assertSame('abc', $chunks['body']);
        self::assertSame('text/plain', $chunks['headers']['content-type']);
    }

    public function testAsyncFanOutInsideARequestOverlapsAndLeaksNothing(): void
    {
        $baseline = self::int($this->request('GET', '/fanout')['json']['coroutines']);
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[$i] = fn(): array => $this->request('GET', '/fanout')['json'];
        }
        [$results, $elapsed] = self::runConcurrently($tasks);
        foreach ($results as $json) {
            $r = self::assoc($json['results']);
            self::assertSame('A', $r['a']);
            self::assertSame('B', $r['b']);
            self::assertSame($json['request_id'], $r['c'], 'child tasks see the request context of their own request');
            self::assertLessThan(450, self::int($json['elapsed_ms']), 'three 200 ms tasks overlap inside the request');
        }
        self::assertLessThan(1.5, $elapsed);
        Sleep::milliseconds(100);
        self::assertLessThanOrEqual($baseline, self::int($this->request('GET', '/fanout')['json']['coroutines']), 'no task coroutines accumulate across requests');

        $timeout = $this->request('GET', '/fanout-timeout');
        self::assertSame(504, $timeout['status']);
    }

    public function testConcurrentRoutedRequestsKeepIndependentState(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 40; $i++) {
            $tasks[$i] = fn(): array => $this->request('GET', '/api/users/' . $i, '', ['Authorization' => 'Bearer t' . $i])['json'];
        }
        [$results, $elapsed] = self::runConcurrently($tasks);
        foreach ($results as $i => $json) {
            self::assertSame($i, $json['id']);
            self::assertSame('user:t' . $i, $json['user'], "auth attribute leaked into request {$i}");
        }
        self::assertLessThan(2.0, $elapsed);
    }
}
