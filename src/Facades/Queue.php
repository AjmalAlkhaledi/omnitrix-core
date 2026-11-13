<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Queue\QueueProducer;

/**
 * Background jobs (see docs/background-jobs.md).
 *
 * ```php
 * Queue::dispatch('emails.send', ['user_id' => 10]);
 * ```
 */
final class Queue extends Facade
{
    public static function producer(): QueueProducer
    {
        return self::resolve(QueueProducer::class);
    }

    /** @param array<string, mixed> $payload */
    public static function dispatch(string $name, array $payload = [], float $delay = 0.0, ?string $queue = null, ?int $maxAttempts = null): string
    {
        return self::producer()->dispatch($name, $payload, $delay, $queue, $maxAttempts);
    }

    /** @return array{ready: int, delayed: int, processing: int, dead: int} */
    public static function stats(?string $queue = null): array
    {
        return self::producer()->stats($queue);
    }
}
