<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

use Psr\Log\LoggerInterface;

/** Writes broadcasts to the log (development). */
final class LogBroadcaster implements Broadcaster
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function publish(array $channels, string $event, array $data): void
    {
        $this->logger->info('broadcast', ['channels' => $channels, 'event' => $event, 'data' => $data]);
    }
}
