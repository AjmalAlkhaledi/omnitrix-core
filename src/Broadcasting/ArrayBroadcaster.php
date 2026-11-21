<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

/** Keeps broadcasts in memory (tests, `null` driver). */
final class ArrayBroadcaster implements Broadcaster
{
    /** @var list<array{list<string>, string, array<string, mixed>}> */
    public array $published = [];

    public function publish(array $channels, string $event, array $data): void
    {
        $this->published[] = [$channels, $event, $data];
    }
}
