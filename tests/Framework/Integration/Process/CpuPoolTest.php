<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Process;

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
use function posix_kill;

/** CPU pool acceptance over a real server: isolation of CPU work from HTTP workers. */
#[CoversNothing]
final class CpuPoolTest extends TestCase
{
    private const int PORT = 18380;

    private static ?ProcessRunner $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/app_server.php', [], ['OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'OMNITRIX_FIXTURE_CPU' => '1']);
        self::$server->waitForPort('127.0.0.1', self::PORT, 15.0);
        Sleep::milliseconds(300); // CPU processes bind their sockets
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop(10.0);
        self::$server = null;
    }

    /** @return array{status: int, json: array<string, mixed>, elapsed: float} */
    private function get(string $path, float $timeout = 20.0): array
    {
        $t = hrtime(true);
        $client = new HttpClient('127.0.0.1', self::PORT);
        $client->set(['timeout' => $timeout]);
        $client->get($path);
        $status = is_int($client->statusCode) ? $client->statusCode : -1;
        $decoded = json_decode(is_string($client->body) ? $client->body : '', true);
        $client->close();

        return ['status' => $status, 'json' => is_array($decoded) ? self::assoc($decoded) : [], 'elapsed' => (hrtime(true) - $t) / 1e9];
    }

    public function testRealCpuWorkRunsOutsideTheHttpWorker(): void
    {
        $r = $this->get('/cpu/primes?limit=200000');
        self::assertSame(200, $r['status'], 'stderr: ' . (string) self::$server?->stderr());
        $result = self::assoc($r['json']['result']);
        self::assertSame(17984, $result['primes'], 'π(200000) = 17984');
        self::assertNotSame($r['json']['http_pid'], $result['pid'], 'the job ran in a different process');
    }

    public function testHttpWorkersStayResponsiveDuringHeavyCpuJobs(): void
    {
        $tasks = [
            'cpu1' => fn(): array => $this->get('/cpu/spin?seconds=1.5'),
            'cpu2' => fn(): array => $this->get('/cpu/spin?seconds=1.5'),
            'probe' => function (): array {
                Sleep::milliseconds(300); // both CPU workers are busy now
                $latencies = [];
                for ($i = 0; $i < 5; $i++) {
                    $latencies[] = $this->get('/')['elapsed'];
                    Sleep::milliseconds(100);
                }

                return $latencies;
            },
        ];
        [$results, $elapsed] = self::runConcurrently($tasks);
        $cpu1 = $results['cpu1'];
        $cpu2 = $results['cpu2'];
        $probe = $results['probe'];
        self::assertIsArray($cpu1);
        self::assertIsArray($cpu2);
        self::assertIsArray($probe);
        self::assertSame('spun', self::assoc($cpu1['json'])['result']);
        self::assertSame('spun', self::assoc($cpu2['json'])['result']);
        foreach ($probe as $latency) {
            self::assertLessThan(0.1, $latency, 'HTTP latency stays low while 2 × 1.5 s CPU jobs run');
        }
        self::assertLessThan(2.5, $elapsed, 'the two CPU jobs ran in parallel on two processes');
    }

    public function testJobExceptionsTimeoutsAndCrashesAreIsolated(): void
    {
        $failed = $this->get('/cpu/fail');
        self::assertSame(503, $failed['status']);
        self::assertSame('Omnitrix\Core\Process\CpuJobException', $failed['json']['error']);
        self::assertSame('InvalidArgumentException', $failed['json']['remote']);
        self::assertSame('bad input for job', $failed['json']['message']);

        $unknown = $this->get('/cpu/nope');
        self::assertSame('Omnitrix\Core\Process\CpuJobException', $unknown['json']['error']);

        $t = hrtime(true);
        $timeout = $this->get('/cpu/spin?seconds=5&timeout=0.5');
        self::assertSame('Omnitrix\Core\Process\CpuTimeoutException', $timeout['json']['error']);
        self::assertStringContainsString('was killed', self::string($timeout['json']['message']));
        self::assertLessThan(1.5, (hrtime(true) - $t) / 1e9);

        $crash = $this->get('/cpu/crash');
        self::assertSame('Omnitrix\Core\Process\WorkerCrashedException', $crash['json']['error']);

        // The manager restarts killed/crashed workers; the pool recovers.
        $ok = null;
        for ($i = 0; $i < 20; $i++) {
            Sleep::milliseconds(250);
            $r = $this->get('/cpu/primes?limit=1000');
            if ($r['status'] === 200) {
                $ok = $r;
                break;
            }
        }
        self::assertNotNull($ok, 'CPU pool recovered after a timeout kill and a crash');
        self::assertSame(168, self::assoc($ok['json']['result'])['primes']);
    }

    public function testGracefulShutdownStopsCpuWorkers(): void
    {
        $r = $this->get('/cpu/primes?limit=1000');
        $cpuPid = self::int(self::assoc($r['json']['result'])['pid']);
        $code = self::$server?->stop(15.0);
        self::$server = null;
        self::assertSame(0, $code, 'server exited cleanly');
        Sleep::milliseconds(200);
        self::assertFalse(@posix_kill($cpuPid, 0), 'CPU worker process is gone after shutdown');
    }
}
