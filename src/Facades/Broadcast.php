<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Broadcasting\BroadcastManager;
use Omnitrix\Broadcasting\Channel;
use Omnitrix\Broadcasting\ShouldBroadcast;

/**
 * Broadcasting (documentation: Async & services → Broadcasting).
 *
 * ```php
 * Event::dispatch(new OrderShipped($order));                       // ShouldBroadcast events are broadcast too
 * Broadcast::send(new PrivateChannel('user.5'), 'notice', ['text' => 'Hi']);
 * ```
 */
final class Broadcast extends Facade
{
    public static function manager(): BroadcastManager
    {
        return self::resolve(BroadcastManager::class);
    }

    public static function event(ShouldBroadcast $event): void
    {
        self::manager()->broadcastEvent($event);
    }

    /**
     * @param Channel|list<Channel> $channels
     * @param array<string, mixed>  $data
     */
    public static function send(Channel|array $channels, string $event, array $data = []): void
    {
        self::manager()->send($channels, $event, $data);
    }
}
