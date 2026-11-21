<?php

declare(strict_types=1);

namespace Omnitrix\Queue;

use Closure;
use InvalidArgumentException;
use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Queue\QueueConsumer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Shutdown\SignalHandler;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;
use Psr\Log\LoggerInterface;

use function is_array;

/**
 * A background job consumer process.
 *
 * ```php
 * $worker = new Worker($config, queue: 'default', concurrency: 8);
 * $worker->handle('emails.send', function (array $payload): void { … });
 * exit($worker->run());
 * ```
 *
 * `$config` is the same array passed to `Application::create()`; its `redis` section selects
 * the queue backend. `run()` blocks until SIGTERM or SIGINT, lets in-flight jobs finish, and
 * returns a process exit code. Handlers must be idempotent: delivery is at least once.
 */
final class Worker
{
    /** @var array<string, callable(array<string, mixed>, Job): void> */
    private array $handlers = [];

    /** @var array<string, int> */
    private array $stats = [];

    private readonly RedisConfig $redis;

    private readonly LoggerInterface $logger;

    /** @param array<string, mixed> $config application configuration with a `redis` section */
    public function __construct(
        array $config,
        private readonly string $queue = 'default',
        private readonly int $concurrency = 4,
        private readonly float $jobTimeout = 60.0,
        private readonly float $backoffBase = 1.0,
        ?LoggerInterface $logger = null,
    ) {
        $redis = $config['redis'] ?? null;
        if (!is_array($redis)) {
            throw new InvalidArgumentException("Queue workers need the 'redis' configuration section");
        }
        /** @var array<string, mixed> $redis */
        $this->redis = RedisConfig::fromArray($redis);
        $this->logger = $logger ?? new JsonLogger();
    }

    /**
     * Registers the handler for one job name.
     *
     * @param callable(array<string, mixed>, Job): void $handler receives the payload (and the job metadata)
     */
    public function handle(string $job, callable $handler): self
    {
        $this->handlers[$job] = $handler;

        return $this;
    }

    /** Processes jobs until terminated; returns 0 after a graceful stop. */
    /** @var (Closure(): bool)|null */
    private ?Closure $paused = null;

    /**
     * No new jobs are taken while `$paused` returns true.
     *
     * @param Closure(): bool $paused
     */
    public function pauseWhen(Closure $paused): self
    {
        $this->paused = $paused;

        return $this;
    }

    public function run(): int
    {
        if ($this->handlers === []) {
            throw new InvalidArgumentException('Register at least one job handler before run()');
        }
        $body = function (): void {
            $client = new RedisClient($this->redis);
            $consumer = new QueueConsumer(new RedisQueue($client), $this->handlers, $this->queue, $this->concurrency, $this->jobTimeout, $this->backoffBase, logger: $this->logger);
            if ($this->paused !== null) {
                $consumer->pauseWhen($this->paused);
            }
            $signals = new SignalHandler();
            $signals->onTerminate(static function () use ($consumer): void {
                $consumer->stop();
            });
            try {
                $consumer->run();
            } finally {
                $signals->release();
                $this->stats = $consumer->stats();
                $client->close(); // stop pool timers so the event loop can exit
            }
        };
        if (Coroutine::getCid() > 0) {
            $body();
        } else {
            Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
            Coroutine::run($body);
        }

        return 0;
    }

    /** @return array<string, int> processed, failed, retried, buried, recovered (after run()) */
    public function stats(): array
    {
        return $this->stats;
    }
}
