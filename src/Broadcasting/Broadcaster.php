<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

/** Publishes events to channels. */
interface Broadcaster
{
    /**
     * @param list<string>         $channels
     * @param array<string, mixed> $data
     */
    public function publish(array $channels, string $event, array $data): void;
}
