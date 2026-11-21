<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Maintenance;

use ArrayObject;
use Omnitrix\Application;
use Omnitrix\Console\Commands\DownCommand;
use Omnitrix\Console\Commands\UpCommand;
use Omnitrix\Console\Console;
use Omnitrix\Core\Http\Server\IncomingRequest;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Queue\QueueConsumer;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Facades\Facade;
use Omnitrix\Http\HttpKernel;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;
use Omnitrix\Maintenance\MaintenanceMode;
use Omnitrix\Maintenance\PreventRequestsDuringMaintenance;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request as SwooleRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Console\Tester\ApplicationTester;

use function array_keys;
use function dirname;
use function fclose;
use function feof;
use function file_put_contents;
use function fread;
use function fwrite;
use function implode;
use function is_resource;
use function microtime;
use function mkdir;
use function sort;
use function stream_socket_client;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function var_export;

#[CoversClass(MaintenanceMode::class)]
#[CoversClass(PreventRequestsDuringMaintenance::class)]
#[CoversClass(DownCommand::class)]
#[CoversClass(UpCommand::class)]
final class MaintenanceModeTest extends TestCase
{
    private const int PORT = 18730;

    public function testRequestsAreRefusedWhileDown(): void
    {
        $app = Application::create(['server' => ['port' => 18731], 'app' => ['maintenance' => ['path' => sys_get_temp_dir() . '/omnitrix-down-' . uniqid()]]]);
        $app->health();
        $app->get('/api/ping', static fn(): Response => Response::json(['pong' => true]));
        $app->withServices(function () use ($app): void {
            $container = Facade::container();
            self::assertNotNull($container);
            $mode = $container->get(MaintenanceMode::class);
            self::assertInstanceOf(MaintenanceMode::class, $mode);
            $kernel = $app->makeKernel($container instanceof \Omnitrix\Core\Container\Container ? $container : null);
            self::assertSame(200, $this->send($kernel, '/api/ping')->getStatus());

            $mode->activate('Upgrading the database', 120, 's3cret');
            $down = $this->send($kernel, '/api/ping');
            self::assertSame(503, $down->getStatus());
            self::assertSame('{"error":"Service Unavailable","message":"Upgrading the database"}', $down->getBody());
            self::assertSame('120', $down->getHeader('Retry-After'));
            self::assertSame(200, $this->send($kernel, '/health/live')->getStatus(), 'health checks stay up');
            self::assertSame(200, $this->send($kernel, '/api/ping', ['X-Maintenance-Bypass' => 's3cret'])->getStatus());
            self::assertSame(503, $this->send($kernel, '/api/ping', ['X-Maintenance-Bypass' => 'guess'])->getStatus());
            self::assertSame(503, $this->send($kernel, '/missing')->getStatus(), 'before routing: unknown paths too');

            $mode->deactivate();
            self::assertSame(200, $this->send($kernel, '/api/ping')->getStatus());
        });
    }

    public function testDownAndUpReachEveryWorkerThroughRedis(): void
    {
        $prefix = 'maintenance-' . uniqid() . ':';
        $server = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/maintenance_server.php', [], ['OMNITRIX_FIXTURE_PORT' => (string) self::PORT, 'FIXTURE_PREFIX' => $prefix]);
        try {
            $server->waitForPort('127.0.0.1', self::PORT, 15.0);
            self::assertSame([200], $this->statuses(8));
            $console = $this->console($prefix);
            self::assertSame(0, $console->run(['command' => 'down', '--message' => 'Back soon', '--retry' => '30', '--secret' => 'letmein']), $console->getDisplay());
            self::assertStringContainsString('X-Maintenance-Bypass: letmein', $console->getDisplay());
            self::assertTrue($this->eventually(fn(): bool => $this->statuses(8) === [503]), 'every worker answers 503 within a second or two');
            $response = $this->get('/api/ping');
            self::assertStringContainsString('HTTP/1.1 503', $response);
            self::assertStringContainsString('Retry-After: 30', $response);
            self::assertStringContainsString('"message":"Back soon"', $response);
            self::assertStringContainsString('HTTP/1.1 200', $this->get('/health/ready'));
            self::assertStringContainsString('HTTP/1.1 200', $this->get('/api/ping', 'X-Maintenance-Bypass: letmein'));
            self::assertSame(0, $console->run(['command' => 'up']));
            self::assertStringContainsString('The application is up', $console->getDisplay());
            self::assertTrue($this->eventually(fn(): bool => $this->statuses(8) === [200]));
            self::assertSame(0, $console->run(['command' => 'up']));
            self::assertStringContainsString('was not down', $console->getDisplay());
        } finally {
            $server->stop(15.0);
        }
    }

    public function testQueueWorkersTakeNoJobsWhileDown(): void
    {
        $redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'maintenance-queue-' . uniqid() . ':']));
        $mode = new MaintenanceMode('redis', null, $redis, refreshSeconds: 0.0);
        $mode->activate();
        /** @var ArrayObject<int, mixed> $done */
        $done = new ArrayObject();
        $consumer = new QueueConsumer(new RedisQueue($redis), ['work' => static function (array $payload, Job $job) use ($done): void {
            $done[] = $payload['n'] ?? null;
        }], 'default', 2);
        $consumer->pauseWhen(static fn(): bool => $mode->active());
        (new QueueProducer(new RedisQueue($redis)))->dispatch('work', ['n' => 1]);
        Coroutine::create(static function () use ($consumer): void {
            $consumer->run();
        });
        try {
            Coroutine::usleep(1_500_000);
            self::assertCount(0, $done, 'paused while down');
            $mode->deactivate();
            self::assertTrue($this->eventually(static fn(): bool => $done->getArrayCopy() === [1]), 'resumed after up');
        } finally {
            $consumer->stop();
            Coroutine::usleep(1_200_000);
            $redis->close();
        }
    }

    /** @param callable(): bool $condition */
    private function eventually(callable $condition, float $timeout = 5.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            Coroutine::usleep(100_000);
        }

        return $condition();
    }

    /** @return list<int> the distinct statuses of `$count` requests (both workers answer) */
    private function statuses(int $count): array
    {
        $seen = [];
        for ($i = 0; $i < $count; $i++) {
            $seen[(int) substr($this->get('/api/ping'), 9, 3)] = true;
        }
        $statuses = array_keys($seen);
        sort($statuses);

        return $statuses;
    }

    private function get(string $target, ?string $header = null): string
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . self::PORT, $errno, $error, 5.0);
        self::assertTrue(is_resource($socket), (string) $error);
        fwrite($socket, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n" . ($header !== null ? $header . "\r\n" : '') . "\r\n");
        $raw = '';
        while (!feof($socket)) {
            $raw .= (string) fread($socket, 8192);
        }
        fclose($socket);

        return $raw;
    }

    private function console(string $prefix): ApplicationTester
    {
        $project = sys_get_temp_dir() . '/omnitrix-maintenance-' . uniqid();
        mkdir($project);
        $fixture = dirname(__DIR__, 2) . '/Fixtures/maintenance_project.php';
        file_put_contents($project . '/bootstrap.php', "<?php\nputenv('FIXTURE_PREFIX=' . " . var_export($prefix, true) . ");\nreturn require " . var_export($fixture, true) . ";\n");
        $application = (new Console($project))->application();
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    /** @param array<string, string> $headers */
    private function send(HttpKernel $kernel, string $target, array $headers = []): Response
    {
        $lines = ["GET {$target} HTTP/1.1", 'Host: api.example.com'];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }
        $raw = SwooleRequest::create([]);
        self::assertInstanceOf(SwooleRequest::class, $raw);
        $raw->parse(implode("\r\n", $lines) . "\r\n\r\n");
        $context = RequestContext::create();
        ContextStorage::bind($context);
        try {
            $request = new Request(new IncomingRequest($raw), $context);
            $context->set(Request::CONTEXT_KEY, $request);

            return $kernel->handle($request);
        } finally {
            ContextStorage::unbind();
        }
    }
}
