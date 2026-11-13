<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\Queue;

use InvalidArgumentException;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Facades\Async;
use Omnitrix\Queue\Worker;
use Omnitrix\Tests\Support\ProcessRunner;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;

use function dirname;
use function microtime;
use function uniqid;

use const SIGTERM;

/** The public queue Worker runs jobs dispatched by producers and stops gracefully. */
#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    public function testWorkerProcessesJobsAndStopsGracefully(): void
    {
        $redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'omnitrix-worker-test:']));
        $redis->withConnection(static fn(Redis $r) => $r->flushDB());
        $queue = 'w' . uniqid();
        $producer = new QueueProducer(new RedisQueue($redis), $queue);
        foreach (['Ann', 'Bo', 'Cy'] as $name) {
            $producer->dispatch('greet', ['name' => $name]);
        }

        $worker = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/queue_worker.php', [], [
            'OMNITRIX_TEST_REDIS_HOST' => Services::redisHost(), 'OMNITRIX_TEST_REDIS_PORT' => (string) Services::redisPort(), 'QUEUE_NAME' => $queue,
        ]);
        try {
            $deadline = microtime(true) + 10.0;
            while ($redis->llen('greetings') < 3 && microtime(true) < $deadline) {
                Async::sleep(0.05);
            }
            self::assertEqualsCanonicalizing(['hello Ann', 'hello Bo', 'hello Cy'], $redis->withConnection(static fn(Redis $r): mixed => $r->lrange('greetings', 0, -1)));
            $worker->signal(SIGTERM);
            self::assertSame(0, $worker->wait(10.0), $worker->stderr());
            self::assertStringContainsString('"processed":3', $worker->stderr());
            self::assertSame(['ready' => 0, 'delayed' => 0, 'processing' => 0, 'dead' => 0], $producer->stats());
        } finally {
            $worker->stop(5.0);
            $redis->close();
        }
    }

    public function testWorkerRequiresRedisConfigurationAndHandlers(): void
    {
        try {
            new Worker([]);
            self::fail('missing redis section accepted');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString("'redis'", $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        (new Worker(['redis' => ['host' => '127.0.0.1']]))->run();
    }
}
