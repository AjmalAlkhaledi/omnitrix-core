<?php

declare(strict_types=1);

namespace Omnitrix\Core\Queue;

use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\TimeoutException;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Support\Id;
use OpenSwoole\Coroutine;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function count;
use function getmypid;
use function min;
use function random_int;

/**
 * Processes jobs from one queue with `$concurrency` coroutines in a long-running consumer
 * process. Graceful: {@see stop()} lets in-flight jobs finish, then {@see run()} returns.
 * Failures retry with exponential backoff up to the job's maxAttempts, then go to the dead
 * letter list. A per-job timeout bounds handlers (the job counts as failed when exceeded).
 *
 * In a standalone `Coroutine::run()` program, close the RedisClient after `run()` returns:
 * its pool keeps an eviction timer that would otherwise keep the event loop alive.
 */
final class QueueConsumer
{
    private bool $running = false;

    public readonly string $consumerId;

    /** @var array<string, int> */
    private array $stats = ['processed' => 0, 'failed' => 0, 'retried' => 0, 'buried' => 0, 'recovered' => 0];

    private int $inFlight = 0;

    /**
     * @param array<string, callable(array<string, mixed>, Job): void> $handlers job name => handler
     */
    public function __construct(
        private readonly RedisQueue $queue,
        private readonly array $handlers,
        private readonly string $queueName = 'default',
        private readonly int $concurrency = 4,
        private readonly float $jobTimeout = 60.0,
        private readonly float $backoffBase = 1.0,
        private readonly float $backoffMax = 300.0,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?string $consumerId = null,
    ) {
        $this->consumerId = $consumerId ?? ((string) getmypid() . '-' . Id::generate());
    }

    /** Blocks until stop() was called and in-flight jobs finished. Run inside a coroutine. */
    public function run(): void
    {
        $this->running = true;
        $this->queue->heartbeat($this->consumerId, 30);
        $this->stats['recovered'] += $this->queue->recover($this->queueName);
        $heartbeat = Coroutine::create(function (): void {
            while ($this->isRunning()) {
                $this->queue->heartbeat($this->consumerId, 30);
                $this->stats['recovered'] += $this->queue->recover($this->queueName);
                for ($i = 0; $i < 50 && $this->isRunning(); $i++) {
                    Sleep::milliseconds(100);
                }
            }
        });
        $this->logger->info('queue consumer started', ['queue' => $this->queueName, 'consumer' => $this->consumerId, 'concurrency' => $this->concurrency]);

        $lanes = [];
        for ($i = 0; $i < $this->concurrency; $i++) {
            $lanes[] = fn() => $this->lane();
        }
        Async::allSettled($lanes);

        $this->running = false;
        while ($heartbeat !== false && Coroutine::exists($heartbeat)) {
            Sleep::milliseconds(20);
        }
        $this->queue->forgetConsumer($this->consumerId);
        $this->logger->info('queue consumer stopped', ['consumer' => $this->consumerId] + $this->stats);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats + ['in_flight' => $this->inFlight];
    }

    private function lane(): void
    {
        while ($this->isRunning()) {
            try {
                $reserved = $this->queue->reserve($this->queueName, $this->consumerId, 1);
            } catch (Throwable $e) {
                $this->logger->error('queue reserve failed', ['exception' => $e]);
                Sleep::milliseconds(500); // Redis down: back off, keep the consumer alive
                continue;
            }
            if ($reserved === null) {
                continue;
            }
            [$job, $raw] = $reserved;
            $this->inFlight++;
            try {
                $this->process($job, $raw);
            } finally {
                $this->inFlight--;
            }
        }
    }

    private function process(Job $job, string $raw): void
    {
        $handler = $this->handlers[$job->name] ?? null;
        try {
            if ($handler === null) {
                throw new QueueException("No handler registered for job '{$job->name}'");
            }
            Async::withTimeout(static function () use ($handler, $job): void {
                $handler($job->payload, $job);
            }, $this->jobTimeout);
            $this->queue->ack($this->queueName, $this->consumerId, $raw);
            $this->stats['processed']++;
        } catch (Throwable $e) {
            $this->stats['failed']++;
            $message = $e instanceof TimeoutException ? "Job exceeded {$this->jobTimeout}s" : $e::class . ': ' . $e->getMessage();
            $next = $job->withAttempt($message);
            if ($handler !== null && $next->hasAttemptsLeft()) {
                $delay = min($this->backoffMax, $this->backoffBase * (2 ** ($next->attempts - 1)));
                $delay += $delay > 0 ? random_int(0, (int) ($delay * 100)) / 1000 : 0.0; // ≤ 10% jitter
                $this->queue->retry($this->queueName, $this->consumerId, $raw, $next, $delay);
                $this->stats['retried']++;
                $this->logger->warning('job failed, retrying', ['job' => $job->id, 'name' => $job->name, 'attempt' => $next->attempts, 'delay' => $delay, 'error' => $message]);
            } else {
                $this->queue->bury($this->queueName, $this->consumerId, $raw, $next);
                $this->stats['buried']++;
                $this->logger->error('job moved to dead letter list', ['job' => $job->id, 'name' => $job->name, 'attempts' => $next->attempts, 'error' => $message]);
            }
        }
    }

    public function handlerCount(): int
    {
        return count($this->handlers);
    }
}
