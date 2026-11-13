<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Redis;

use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function dirname;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function uniqid;

/** Cache and Redis facades inside request handlers across concurrent requests. */
#[CoversNothing]
final class CacheOverHttpTest extends TestCase
{
    private const int PORT = 18370;

    private static ?ProcessRunner $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/app_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'OMNITRIX_FIXTURE_REDIS' => '1',
            'OMNITRIX_TEST_REDIS_HOST' => Services::redisHost(), 'OMNITRIX_TEST_REDIS_PORT' => (string) Services::redisPort(),
        ]);
        self::$server->waitForPort('127.0.0.1', self::PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(10.0);
        self::$server = null;
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function get(string $path): array
    {
        $client = new HttpClient('127.0.0.1', self::PORT);
        $client->set(['timeout' => 10.0]);
        $client->get($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $decoded = json_decode(is_string($client->body) ? $client->body : '', true);
        $client->close();

        return ['status' => $status, 'json' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    public function testCacheIsSharedAcrossWorkersAndRequests(): void
    {
        $key = uniqid('k');
        $first = $this->get('/cache/' . $key);
        self::assertSame(200, $first['status'], 'stderr: ' . (string) self::$server?->stderr());
        self::assertSame(1, $first['json']['hits']);
        $computedBy = self::assoc($first['json']['value'])['by'];

        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $tasks[$i] = fn(): array => $this->get('/cache/' . $key)['json'];
        }
        [$results, $elapsed] = self::runConcurrently($tasks);
        $hits = [];
        foreach ($results as $json) {
            self::assertSame($computedBy, self::assoc($json['value'])['by'], 'remember() returned the cached value, computed once');
            $hits[] = self::int($json['hits']);
        }
        sort($hits);
        self::assertSame(range(2, 21), $hits, 'atomic increments across 2 workers × 20 concurrent requests');
        self::assertLessThan(2.0, $elapsed);
        $after = $this->get('/cache/' . $key)['json'];
        self::assertSame(0, self::assoc($after['redis_pool'])['in_use'], 'no Redis connection stays leased once requests finished');
        self::assertLessThanOrEqual(8, self::assoc($after['redis_pool'])['size'], 'pool bounded by max');
    }
}
