<?php

declare(strict_types=1);

/*
 * Standalone queue consumer used by the queue integration tests.
 * Env: OMNITRIX_TEST_REDIS_HOST/PORT, QUEUE_NAME, QUEUE_CONCURRENCY, QUEUE_JOB_TIMEOUT.
 * Jobs write their effects to Redis so the test can observe them.
 */

use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Queue\Job;
use Omnitrix\Core\Queue\QueueConsumer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Context\SharedContext;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Runtime\Shutdown\SignalHandler;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$env = static function (string $name, string $default): string {
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $default;
};

Coroutine::set(['log_file' => dirname(__DIR__, 3) . '/var/log/fixture-queue.log']);
Runtime::enableCoroutine(true, Runtime::HOOK_ALL);

Coroutine::run(static function () use ($env): void {
    $redis = new RedisClient(RedisConfig::fromArray(['host' => $env('OMNITRIX_TEST_REDIS_HOST', '127.0.0.1'), 'port' => (int) $env('OMNITRIX_TEST_REDIS_PORT', '63790'), 'prefix' => 'omnitrix-queue-test:']));
    $queue = new RedisQueue($redis);
    $handlers = [
        'record' => static function (array $payload, Job $job) use ($redis): void {
            $redis->rpush('done', (string) json_encode(['id' => $payload['id'] ?? null, 'pid' => getmypid(), 'attempt' => $job->attempts]));
        },
        'slow' => static function (array $payload) use ($redis): void {
            $redis->rpush('started', is_string($payload['id'] ?? null) ? $payload['id'] : '');
            Sleep::for(is_int($payload['seconds'] ?? null) || is_float($payload['seconds'] ?? null) ? (float) $payload['seconds'] : 1.0);
            $redis->rpush('done', (string) json_encode(['id' => $payload['id'] ?? null, 'pid' => getmypid()]));
        },
        'flaky' => static function (array $payload, Job $job) use ($redis): void {
            $redis->rpush('attempts', (string) $job->attempts);
            if ($job->attempts < 2) {
                throw new RuntimeException('transient failure #' . $job->attempts);
            }
            $redis->rpush('done', (string) json_encode(['id' => $payload['id'] ?? null, 'attempt' => $job->attempts]));
        },
        'context' => static function () use ($redis): void {
            // The shared context of the dispatching request is restored for the job.
            (new JsonLogger())->info('job with context');
            $redis->rpush('done', (string) json_encode(SharedContext::dehydrate()));
        },
        'always-fails' => static function (): never {
            throw new DomainException('permanent failure');
        },
    ];
    $consumer = new QueueConsumer($queue, $handlers, $env('QUEUE_NAME', 'default'), (int) $env('QUEUE_CONCURRENCY', '4'), (float) $env('QUEUE_JOB_TIMEOUT', '10'), backoffBase: 0.2, logger: new JsonLogger());
    $signals = new SignalHandler();
    $signals->onTerminate(static function () use ($consumer): void {
        $consumer->stop();
    });
    $consumer->run();
    $signals->release();
    $redis->close(); // stops the pool's eviction timer so the event loop can exit
    fwrite(STDERR, 'consumer exited ' . json_encode($consumer->stats()) . "\n");
});
