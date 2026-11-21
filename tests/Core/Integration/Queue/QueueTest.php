<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Queue;

use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Context\ContextStorage;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Context\SharedContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Redis;
use RedisCluster;

use function dirname;
use function explode;
use function is_array;
use function json_decode;
use function microtime;
use function preg_match;
use function str_contains;
use function uniqid;

use const SIGKILL;
use const SIGTERM;

/** Durable execution, retries/backoff, dead letters, crash recovery and graceful shutdown. */
#[CoversNothing]
final class QueueTest extends TestCase
{
    private ?RedisClient $redis = null;

    /** @var list<ProcessRunner> */
    private array $workers = [];

    private string $queueName = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'omnitrix-queue-test:']));
        $this->redis->withConnection(static fn(Redis|RedisCluster $r) => $r->flushDB());
        $this->queueName = 'q' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(10.0);
        }
        $this->workers = [];
        $this->redis?->close();
        $this->redis = null;
        parent::tearDown();
    }

    private function redis(): RedisClient
    {
        return $this->redis ?? self::fail('redis not initialised');
    }

    private function queue(): RedisQueue
    {
        return new RedisQueue($this->redis());
    }

    private function startWorker(int $concurrency = 4, float $jobTimeout = 10.0): ProcessRunner
    {
        $worker = new ProcessRunner(dirname(__DIR__, 2) . '/Fixtures/queue_worker.php', [], [
            'OMNITRIX_TEST_REDIS_HOST' => Services::redisHost(), 'OMNITRIX_TEST_REDIS_PORT' => (string) Services::redisPort(),
            'QUEUE_NAME' => $this->queueName, 'QUEUE_CONCURRENCY' => (string) $concurrency, 'QUEUE_JOB_TIMEOUT' => (string) $jobTimeout,
        ]);
        $this->workers[] = $worker;

        return $worker;
    }

    /** @param callable(): bool $condition */
    private static function eventually(callable $condition, float $timeout = 10.0): bool
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return true;
            }
            Sleep::milliseconds(50);
        }

        return $condition();
    }

    public function testJobsAreExecutedConcurrentlyByAConsumerProcess(): void
    {
        $producer = new QueueProducer($this->queue(), $this->queueName);
        for ($i = 0; $i < 20; $i++) {
            $producer->dispatch('record', ['id' => $i]);
        }
        self::assertSame(20, $producer->stats()['ready'], 'jobs are durable before any consumer runs');

        $this->startWorker();
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('done') === 20), 'all jobs executed');
        self::assertSame(['ready' => 0, 'delayed' => 0, 'processing' => 0, 'dead' => 0], $producer->stats());

        for ($i = 0; $i < 4; $i++) {
            $producer->dispatch('slow', ['id' => 's' . $i, 'seconds' => 1.0]);
        }
        $t = microtime(true);
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('done') === 24, 5.0));
        self::assertLessThan(2.0, microtime(true) - $t, '4 × 1 s jobs ran concurrently on 4 lanes');
    }

    public function testTheSharedContextTravelsWithJobsAndIntoLogs(): void
    {
        $context = RequestContext::create();
        ContextStorage::bind($context);
        try {
            SharedContext::set('tenant', 7);
            SharedContext::set('api_key', 'k-1', hidden: true);
            (new QueueProducer($this->queue(), $this->queueName))->dispatch('context');
        } finally {
            ContextStorage::unbind();
        }
        $worker = $this->startWorker();
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('done') === 1), 'job executed');
        self::assertSame('{"visible":{"tenant":7},"hidden":{"api_key":"k-1"}}', $this->redis()->lpop('done'));
        self::assertTrue(self::eventually(static fn(): bool => str_contains($worker->stderr(), '"message":"job with context"')));
        $line = '';
        foreach (explode("\n", $worker->stderr()) as $candidate) {
            if (str_contains($candidate, '"message":"job with context"')) {
                $line = $candidate;
            }
        }
        self::assertStringContainsString('"context":{"tenant":7}', $line, 'visible values are logged');
        self::assertStringNotContainsString('k-1', $line, 'hidden values are not');
    }

    public function testRetriesWithBackoffAndDeadLetters(): void
    {
        $producer = new QueueProducer($this->queue(), $this->queueName);
        $producer->dispatch('flaky', ['id' => 'f'], maxAttempts: 3);
        $deadId = $producer->dispatch('always-fails', [], maxAttempts: 2);
        $producer->dispatch('unknown-job', []);
        $this->startWorker();

        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('done') === 1, 10.0), 'flaky job succeeded after retries');
        self::assertSame(['0', '1', '2'], $this->redis()->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->lrange('attempts', 0, -1)), 'two failures then success');
        self::assertTrue(self::eventually(fn(): bool => $this->queue()->stats($this->queueName)['dead'] === 2, 10.0));
        $dead = $this->queue()->deadLetters($this->queueName);
        $byName = [];
        foreach ($dead as $job) {
            $byName[$job->name] = $job;
        }
        self::assertSame($deadId, $byName['always-fails']->id);
        self::assertSame(2, $byName['always-fails']->attempts);
        self::assertStringContainsString('permanent failure', (string) $byName['always-fails']->lastError);
        self::assertStringContainsString('No handler registered', (string) $byName['unknown-job']->lastError);
        self::assertSame(1, $byName['unknown-job']->attempts, 'jobs without a handler are not retried');
    }

    public function testDelayedJobsRunAfterTheirDelay(): void
    {
        $producer = new QueueProducer($this->queue(), $this->queueName);
        $this->startWorker();
        $t = microtime(true);
        $producer->dispatch('record', ['id' => 'later'], delay: 1.5);
        self::assertSame(1, $producer->stats()['delayed']);
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('done') === 1, 6.0));
        self::assertGreaterThanOrEqual(1.4, microtime(true) - $t);
    }

    public function testCrashedConsumerJobsAreRecoveredByAnotherConsumer(): void
    {
        $producer = new QueueProducer($this->queue(), $this->queueName);
        $victim = $this->startWorker(concurrency: 1);
        $producer->dispatch('slow', ['id' => 'crash-me', 'seconds' => 30]);
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('started') === 1, 5.0));
        $victim->signal(SIGKILL);
        $victim->wait(5.0);
        self::assertSame(1, $this->queue()->stats($this->queueName)['processing'], 'the job is held by the dead consumer, not lost');

        // Fast-forward heartbeat expiry instead of waiting 30 s.
        $this->redis()->withConnection(function (Redis|RedisCluster $r): void {
            $iterator = null;
            $keys = $r->scan($iterator, 'omnitrix-queue-test:queue:consumer:*', 100);
            foreach (is_array($keys) ? $keys : [] as $key) {
                $r->rawcommand('DEL', $key);
            }
        });
        $this->redis()->delete('started');
        $this->redis()->withConnection(static fn(Redis|RedisCluster $r) => $r->rawcommand('SET', 'omnitrix-queue-test:noop', '1'));

        // Shorten the recovered job by editing it is not possible; instead a new consumer runs it.
        $this->startWorker(concurrency: 1, jobTimeout: 1.0);
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('started') === 1, 10.0), 'job re-delivered to the new consumer');
        self::assertTrue(self::eventually(fn(): bool => $this->queue()->stats($this->queueName)['processing'] === 0 && $this->queue()->stats($this->queueName)['delayed'] + $this->queue()->stats($this->queueName)['dead'] >= 1, 10.0), 'job timeout counted as a failure');
    }

    public function testGracefulShutdownFinishesInFlightJobs(): void
    {
        $producer = new QueueProducer($this->queue(), $this->queueName);
        $worker = $this->startWorker(concurrency: 2);
        $producer->dispatch('slow', ['id' => 'a', 'seconds' => 1.0]);
        $producer->dispatch('slow', ['id' => 'b', 'seconds' => 1.0]);
        self::assertTrue(self::eventually(fn(): bool => $this->redis()->llen('started') === 2, 5.0));
        $worker->signal(SIGTERM);
        self::assertSame(0, $worker->wait(10.0), 'consumer exited cleanly: ' . $worker->stderr());
        self::assertSame(2, $this->redis()->llen('done'), 'in-flight jobs completed before exit');
        self::assertSame(['ready' => 0, 'delayed' => 0, 'processing' => 0, 'dead' => 0], $producer->stats());
        self::assertSame(1, preg_match('/^consumer exited (\{.*\})$/m', $worker->stderr(), $m), $worker->stderr());
        $stats = json_decode($m[1] ?? "", true);
        self::assertIsArray($stats);
        self::assertSame(2, $stats['processed']);
    }

    public function testPoisonMessagesAreParkedNotLooped(): void
    {
        $this->redis()->lpush(RedisQueue::key($this->queueName, 'ready'), '{not json');
        self::assertNull($this->queue()->reserve($this->queueName, 'tester', 1));
        self::assertSame(1, $this->queue()->stats($this->queueName)['dead']);
        self::assertSame(0, $this->queue()->stats($this->queueName)['ready']);
        $this->queue()->push(Job::create('record', ['id' => 1], $this->queueName));
        $reserved = $this->queue()->reserve($this->queueName, 'tester', 1);
        self::assertNotNull($reserved);
        self::assertSame(1, $this->queue()->stats($this->queueName)['processing']);
        $this->queue()->ack($this->queueName, 'tester', $reserved[1]);
        self::assertSame(0, $this->queue()->stats($this->queueName)['processing']);
        $this->queue()->purge($this->queueName);
        self::assertSame(['ready' => 0, 'delayed' => 0, 'processing' => 0, 'dead' => 0], $this->queue()->stats($this->queueName));
    }
}
