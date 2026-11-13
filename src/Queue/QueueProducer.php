<?php

declare(strict_types=1);

namespace Omnitrix\Core\Queue;

/** Producer side of the queue, used by request handlers (one per worker). */
final class QueueProducer
{
    public function __construct(private readonly RedisQueue $queue, private readonly string $defaultQueue = 'default', private readonly int $defaultMaxAttempts = 3) {}

    /**
     * Enqueues `$name` with a JSON-compatible payload; returns the job id.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $name, array $payload = [], float $delay = 0.0, ?string $queue = null, ?int $maxAttempts = null): string
    {
        $job = Job::create($name, $payload, $queue ?? $this->defaultQueue, $maxAttempts ?? $this->defaultMaxAttempts);
        $this->queue->push($job, $delay);

        return $job->id;
    }

    /** @return array{ready: int, delayed: int, processing: int, dead: int} */
    public function stats(?string $queue = null): array
    {
        return $this->queue->stats($queue ?? $this->defaultQueue);
    }
}
