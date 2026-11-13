<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\HttpClient;

use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use PHPUnit\Framework\Attributes\CoversNothing;

use function dirname;
use function hrtime;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

/** The Http facade inside request handlers: outbound waits never block unrelated inbound requests. */
#[CoversNothing]
final class OutboundDoesNotBlockServerTest extends TestCase
{
    private const int APP_PORT = 18360;

    private const int UPSTREAM_PORT = 18602;

    private static ?ProcessRunner $app = null;

    private static ?ProcessRunner $upstream = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        self::$upstream = new ProcessRunner($root . '/tests/Fixtures/upstream_server.php', [(string) self::UPSTREAM_PORT, 'http']);
        self::$app = new ProcessRunner($root . '/tests/Fixtures/app_server.php', [], ['OMNITRIX_FIXTURE_PORT' => (string) self::APP_PORT]);
        self::$upstream->waitForPort('127.0.0.1', self::UPSTREAM_PORT, 15.0);
        self::$app->waitForPort('127.0.0.1', self::APP_PORT, 15.0);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->stop(10.0);
        self::$upstream?->stop(5.0);
        self::$app = self::$upstream = null;
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function get(string $path): array
    {
        $client = new HttpClient('127.0.0.1', self::APP_PORT);
        $client->set(['timeout' => 10.0]);
        $client->get($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $decoded = json_decode(is_string($client->body) ? $client->body : '', true);
        $client->close();

        return ['status' => $status, 'json' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    public function testUnrelatedRequestsAreServedWhileOutboundCallsWait(): void
    {
        $proxy = $this->get('/proxy?upstream=' . self::UPSTREAM_PORT . '&ms=100');
        self::assertSame(200, $proxy['status'], 'proxy route works through the Http facade');
        self::assertSame(200, $proxy['json']['upstream_status']);

        $tasks = [];
        for ($i = 0; $i < 20; $i++) {
            $tasks['proxy' . $i] = fn(): int => $this->get('/proxy?upstream=' . self::UPSTREAM_PORT . '&ms=600')['status'];
        }
        $tasks['unrelated'] = function (): float {
            Sleep::milliseconds(150); // the 20 outbound calls are in flight now
            $t = hrtime(true);
            self::assertSame(200, $this->get('/')['status']);

            return (hrtime(true) - $t) / 1e9;
        };
        [$results, $elapsed] = self::runConcurrently($tasks);
        self::assertLessThan(0.15, $results['unrelated'], 'an unrelated request must not wait behind outbound calls');
        unset($results['unrelated']);
        self::assertSame([200], array_values(array_unique($results)));
        self::assertLessThan(2.5, $elapsed, '20 × 600 ms outbound waits overlap (serial would be 12 s)');
    }
}
