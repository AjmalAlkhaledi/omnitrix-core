<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Production;

use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_count_values;
use function dirname;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function preg_match;
use function substr_count;

/** Production middleware over a real server with real MariaDB and Redis. */
#[CoversNothing]
final class ProductionFeaturesTest extends TestCase
{
    private const int PORT = 18390;

    private static ?ProcessRunner $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/production_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT,
            'OMNITRIX_TEST_DB_HOST' => Services::dbHost(), 'OMNITRIX_TEST_DB_PORT' => (string) Services::dbPort(), 'OMNITRIX_TEST_DB_NAME' => Services::dbName(),
            'OMNITRIX_TEST_DB_USER' => Services::dbUser(), 'OMNITRIX_TEST_DB_PASS' => Services::dbPassword(),
            'OMNITRIX_TEST_REDIS_HOST' => Services::redisHost(), 'OMNITRIX_TEST_REDIS_PORT' => (string) Services::redisPort(),
        ]);
        self::$server->waitForPort('127.0.0.1', self::PORT, 15.0);
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
    private function request(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $client = new HttpClient('127.0.0.1', self::PORT);
        $client->set(['timeout' => 10.0]);
        $client->setMethod($method);
        $client->setHeaders($headers);
        if ($body !== null) {
            $client->setData($body);
        }
        $client->execute($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $raw = is_string($client->body) ? $client->body : '';
        $respHeaders = [];
        foreach (is_array($client->headers) ? $client->headers : [] as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $respHeaders[$k] = $v;
            }
        }
        $client->close();
        $decoded = json_decode($raw, true);

        return ['status' => $status, 'headers' => $respHeaders, 'body' => $raw, 'json' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    public function testAuthenticationContextIsIsolatedUnder200ConcurrentUsers(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 200; $i++) {
            $tasks[$i] = fn(): array => $this->request('GET', '/api/me', ['Authorization' => 'Bearer user-' . $i]);
        }
        // Bounded at 100 in-flight clients: small kernel listen backlogs overflow under larger
        // bursts of new connections, which only adds SYN-retransmit latency.
        $results = Async::all($tasks, concurrency: 100);
        foreach ($results as $i => $r) {
            self::assertSame(200, $r['status'], "user {$i}: " . $r['body']);
            self::assertSame((string) $i, $r['json']['id'], "user {$i} saw another user's identity");
            self::assertTrue($r['json']['stable']);
            self::assertTrue($r['json']['context_id']);
        }
    }

    public function testAuthorizationAndAuthenticationFailures(): void
    {
        self::assertSame(401, $this->request('GET', '/api/me')['status']);
        self::assertSame(401, $this->request('GET', '/api/me', ['Authorization' => 'Bearer forged'])['status']);
        self::assertSame(403, $this->request('GET', '/api/admin', ['Authorization' => 'Bearer user-1'])['status']);
        self::assertSame(200, $this->request('GET', '/api/admin', ['Authorization' => 'Bearer admin-secret-token'])['status']);
    }

    public function testValidationReturns422WithFieldErrors(): void
    {
        $headers = ['Authorization' => 'Bearer user-1', 'Content-Type' => 'application/json'];
        $bad = $this->request('POST', '/api/users', $headers, (string) json_encode(['email' => 'not-an-email', 'is_admin' => true]));
        self::assertSame(422, $bad['status']);
        $errors = self::assoc(self::assoc($bad['json']['error'])['errors']);
        self::assertSame(['The name field is required.'], $errors['name']);
        self::assertSame(['The email must be a valid email address.'], $errors['email']);
        $ok = $this->request('POST', '/api/users', $headers, (string) json_encode(['name' => 'Ann', 'email' => 'ann@x.io', 'is_admin' => true]));
        self::assertSame(201, $ok['status']);
        self::assertSame(['name' => 'Ann', 'email' => 'ann@x.io'], $ok['json']['data'], 'unvalidated fields are dropped');
    }

    public function testRateLimitIsEnforcedAcrossWorkersUnderConcurrency(): void
    {
        $tasks = [];
        for ($i = 0; $i < 12; $i++) {
            $tasks[$i] = fn(): int => $this->request('GET', '/api/limited', ['Authorization' => 'Bearer user-' . $i])['status'];
        }
        [$statuses] = self::runConcurrently($tasks);
        $counts = array_count_values($statuses);
        ksort($counts); // the order of the counts depends on which answer arrived first
        self::assertSame([200 => 5, 429 => 7], $counts, 'exactly the limit is allowed, shared across both workers');
        $blocked = $this->request('GET', '/api/limited', ['Authorization' => 'Bearer user-1']);
        self::assertSame(429, $blocked['status']);
        self::assertGreaterThanOrEqual(1, (int) $blocked['headers']['retry-after']);
    }

    public function testSecurityHeadersHealthAndMetrics(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->request('GET', '/api/me', ['Authorization' => 'Bearer user-' . $i]);
        }
        $r = $this->request('GET', '/api/me', ['Authorization' => 'Bearer user-7']);
        self::assertSame('nosniff', $r['headers']['x-content-type-options']);
        self::assertSame('DENY', $r['headers']['x-frame-options']);
        self::assertSame('no-store', $r['headers']['cache-control']);

        self::assertSame(['status' => 'ok'], $this->request('GET', '/health/live')['json']);
        $ready = $this->request('GET', '/health/ready');
        self::assertSame(200, $ready['status'], $ready['body']);
        self::assertSame(['database' => 'ok', 'redis' => 'ok'], $ready['json']['checks']);

        $metrics = $this->request('GET', '/metrics');
        self::assertSame(200, $metrics['status']);
        self::assertStringStartsWith('text/plain', $metrics['headers']['content-type']);
        self::assertSame(1, preg_match('/omnitrix_http_requests_total\{route="\/api\/me",method="GET",status="200"\} (\d+)/', $metrics['body'], $m));
        self::assertGreaterThanOrEqual(21, (int) ($m[1] ?? 0), 'requests handled by both workers are aggregated');
        self::assertSame(404, $this->request('GET', '/nope')['status']);
        self::assertStringContainsString('omnitrix_http_requests_total{route="unmatched",method="GET",status="404"}', $this->request('GET', '/metrics')['body']);
        self::assertMatchesRegularExpression('/omnitrix_event_loop_lag_ms\{worker="\d"\} [0-9.]+/', $metrics['body']);
        self::assertMatchesRegularExpression('/omnitrix_db_pool_in_use\{connection="default",worker="\d"\} 0/', $metrics['body']);

        // Every worker publishes its statistics each second: one scrape shows both workers.
        \Omnitrix\Core\Runtime\Coroutine\Sleep::milliseconds(1300);
        $body = $this->request('GET', '/metrics')['body'];
        foreach (['0', '1'] as $worker) {
            self::assertStringContainsString("omnitrix_db_pool_in_use{connection=\"default\",worker=\"{$worker}\"}", $body, "worker {$worker} is reported");
        }
        self::assertSame(1, substr_count($body, '# TYPE omnitrix_db_pool_in_use gauge'));
    }

    public function testLogsAreStructuredAndNeverContainCredentials(): void
    {
        $this->request('GET', '/api/admin', ['Authorization' => 'Bearer admin-secret-token']);
        $this->request('GET', '/api/me?password=hunter2', ['Authorization' => 'Bearer user-3']);
        \Omnitrix\Core\Runtime\Coroutine\Sleep::milliseconds(100);
        $stderr = (string) self::$server?->stderr();
        self::assertStringContainsString('"message":"request"', $stderr);
        self::assertStringContainsString('"route":"/api/admin"', $stderr);
        self::assertStringNotContainsString('admin-secret-token', $stderr);
        self::assertStringNotContainsString('hunter2', $stderr);
    }
}
