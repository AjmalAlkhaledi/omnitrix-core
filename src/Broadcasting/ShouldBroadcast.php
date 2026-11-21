<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

/**
 * An event that is also broadcast when dispatched. Optional methods: `broadcastAs(): string` (the
 * event name, default: the class name without namespace) and `broadcastWith(): array` (the data,
 * default: the public properties).
 */
interface ShouldBroadcast
{
    /** @return Channel|list<Channel> */
    public function broadcastOn(): Channel|array;
}
