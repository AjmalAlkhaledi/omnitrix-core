<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Runtime;

use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Http\Client;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_filter;
use function array_map;
use function array_unique;
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
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;

use const SIGTERM;
use const SIGUSR1;

/**
 * Runtime Core acceptance: real OpenSwoole server process built through Application, driven
 * by real HTTP clients. Lifecycle events are observed through a marker file written by the
 * fixture's worker hooks.
 */
#[CoversNothing]
final class RuntimeServerTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private ?ProcessRunner $server = null;

    private string $markers = '';

    private int $port = 0;

    /**
     * Process mode drains in-flight requests on SIGTERM; simple mode (verified OpenSwoole 26.2
     * limitation, see ServerMode) closes connections of suspended requests immediately.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'process mode' => ['process', true];
        yield 'simple mode' => ['simple', false];
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->server->stop(10.0);
            $this->server = null;
        }
        if ($this->markers !== '' && file_exists($this->markers)) {
            unlink($this->markers);
        }
        parent::tearDown();
    }

    private function start(string $mode, int $workers = 2): void
    {
        $markers = tempnam(sys_get_temp_dir(), 'omnitrix-markers-');
        self::assertNotFalse($markers);
        $this->markers = $markers;
        $this->port = 18200 + ($mode === 'process' ? 10 : 0) + $workers;

        $this->server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/runtime_server.php', [], [
            'OMNITRIX_FIXTURE_PORT' => (string) $this->port,
            'OMNITRIX_FIXTURE_MODE' => $mode,
            'OMNITRIX_FIXTURE_WORKERS' => (string) $workers,
            'OMNITRIX_FIXTURE_MARKERS' => $this->markers,
        ]);
        $this->server->waitForPort(self::HOST, $this->port, 15.0);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function get(string $path, float $timeout = 5.0): array
    {
        $client = new Client(self::HOST, $this->port);
        $client->set(['timeout' => $timeout]);
        $client->get($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $body = is_string($client->body) ? $client->body : '';
        $client->close();
        $decoded = json_decode($body, true);

        return ['status' => $status, 'body' => is_array($decoded) ? self::assoc($decoded) : []];
    }

    /** @return list<array<string, mixed>> */
    private function markers(?string $event = null): array
    {
        $raw = trim((string) file_get_contents($this->markers));
        if ($raw === '') {
            return [];
        }
        $lines = array_map(static fn(string $l): array => self::decodeJsonObject($l), explode("\n", $raw));

        return array_values(array_filter($lines, static fn(array $m): bool => $event === null || ($m['event'] ?? null) === $event));
    }

    #[DataProvider('modes')]
    public function testStartsServesAndStopsGracefullyWithWorkerHooksAndResourceCleanup(string $mode, bool $drainsInFlight): void
    {
        $this->start($mode, 2);

        $info = $this->get('/info');
        self::assertSame(200, $info['status']);
        self::assertSame(32, strlen(self::string($info['body']['request_id'])));
        self::assertGreaterThan(0, self::int($info['body']['cid']));

        Sleep::milliseconds(200);
        $starts = $this->markers('worker.start');
        self::assertCount(2, $starts, 'one start hook per worker');
        self::assertSame(['http'], array_values(array_unique(array_map(static fn(array $m): string => self::string($m['role']), $starts))));

        // Graceful stop: an in-flight 1s request completes, then workers stop, resources release.
        $inflight = null;
        $stopped = new Channel(1);
        Coroutine::create(function () use (&$inflight, $stopped): void {
            Sleep::milliseconds(200);
            $inflight = $this->server?->isRunning();
            $this->server?->signal(SIGTERM);
            $stopped->push($this->server?->wait(15.0));
        });
        [$results, $elapsed] = self::runConcurrently(['slow' => fn(): array => $this->get('/slow', 10.0)]);
        $slow = $results['slow'];
        $exitCode = $stopped->pop(20.0);

        self::assertTrue($inflight);
        if ($drainsInFlight) {
            self::assertSame(200, $slow['status'], 'in-flight request must complete during graceful shutdown');
            self::assertEqualsWithDelta(1.0, $slow['body']['slept'], 0.001);
            self::assertGreaterThanOrEqual(1.0, $elapsed);
        } else {
            self::assertSame(-3, $slow['status'], 'simple mode drops suspended requests on SIGTERM (documented limitation)');
        }
        self::assertSame(0, $exitCode, 'server exit code; stderr: ' . ($this->server?->stderr() ?? ''));

        self::assertCount(2, $this->markers('worker.stop'));
        self::assertCount(2, $this->markers('resource.released'));
        $this->server = null;
    }

    public function testRequestContextIsIsolatedBetweenConcurrentRequestsAndInheritedByChildren(): void
    {
        $this->start('simple', 2);

        $tasks = [];
        for ($i = 0; $i < 30; $i++) {
            $tasks[$i] = fn(): array => $this->get('/context?v=val' . $i);
        }
        [$results] = self::runConcurrently($tasks);

        $ids = [];
        foreach ($results as $i => $r) {
            self::assertSame(200, $r['status']);
            self::assertSame('val' . $i, $r['body']['own'], "request {$i} saw another request's value");
            self::assertSame('val' . $i, $r['body']['child'], "child coroutine of request {$i} did not inherit");
            self::assertTrue($r['body']['same_id']);
            $ids[] = self::string($r['body']['request_id']);
        }
        self::assertCount(30, array_unique($ids));
    }

    public function testHandlerExceptionsAreContainedAndDeferredCallbacksRun(): void
    {
        $this->start('simple', 1);
        $pidBefore = $this->get('/info')['body']['pid'];

        $failed = $this->get('/throw');
        self::assertSame(500, $failed['status']);
        self::assertSame('Internal Server Error', $failed['body']['error']);
        self::assertArrayNotHasKey('trace', $failed['body']);

        $child = $this->get('/throw-child');
        self::assertSame(200, $child['status']);

        $deferred = $this->get('/defer');
        Sleep::milliseconds(100);
        $marks = $this->markers('request.deferred');
        self::assertCount(1, $marks);
        self::assertSame($deferred['body']['request_id'], $marks[0]['request_id']);

        self::assertSame($pidBefore, $this->get('/info')['body']['pid'], 'worker survived both failures');
        self::assertStringContainsString('handler failure', (string) $this->server?->stderr());
        self::assertStringContainsString('child failure', (string) $this->server?->stderr());
    }

    public function testReloadReplacesWorkersAndRunsTheLifecycleAgain(): void
    {
        $this->start('simple', 2);
        $pidsBefore = $this->workerPids();

        for ($round = 1; $round <= 3; $round++) {
            $this->server?->signal(SIGUSR1);
            Sleep::milliseconds(600);
            self::assertSame(200, $this->get('/info')['status'], "serving after reload {$round}");
        }

        $pidsAfter = $this->workerPids();
        self::assertSame([], array_intersect($pidsBefore, $pidsAfter), 'all workers replaced');
        self::assertCount(8, $this->markers('worker.start'), '2 initial + 3 reloads × 2');
        self::assertCount(6, $this->markers('worker.stop'));
        self::assertCount(6, $this->markers('resource.released'));
    }

    public function testCrashedWorkerIsRespawnedAndServiceContinues(): void
    {
        $this->start('simple', 2);
        $before = $this->workerPids();

        $client = new Client(self::HOST, $this->port);
        $client->set(['timeout' => 2.0]);
        $client->get('/fatal');
        $client->close();

        $deadline = hrtime(true) + 10e9;
        do {
            Sleep::milliseconds(100);
            $ok = $this->get('/info')['status'] === 200;
        } while (!$ok && hrtime(true) < $deadline);
        self::assertTrue($ok, 'service recovered after worker crash');

        Sleep::milliseconds(500);
        $after = $this->workerPids();
        self::assertCount(2, $after);
        self::assertNotSame($before, $after, 'a replacement worker has a new pid');
        self::assertGreaterThanOrEqual(3, count($this->markers('worker.start')));
    }

    /** @return list<int> */
    private function workerPids(): array
    {
        $pids = [];
        for ($i = 0; $i < 40 && count($pids) < 2; $i++) {
            $pids[] = self::int($this->get('/info')['body']['pid']);
            $pids = array_values(array_unique($pids));
        }
        sort($pids);

        return $pids;
    }
}
