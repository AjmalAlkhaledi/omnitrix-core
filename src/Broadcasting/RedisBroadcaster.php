<?php

declare(strict_types=1);

namespace Omnitrix\Broadcasting;

use Omnitrix\Core\Redis\RedisClient;

use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Publishes `{"event", "data"}` JSON to Redis channel `broadcast:{channel}`: every server's stream
 * endpoint (and any other subscriber, such as a WebSocket gateway) receives it.
 */
final class RedisBroadcaster implements Broadcaster
{
    public const string PREFIX = 'broadcast:';

    public function __construct(private readonly RedisClient $redis) {}

    public function publish(array $channels, string $event, array $data): void
    {
        $message = json_encode(['event' => $event, 'data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($channels as $channel) {
            $this->redis->publish(self::PREFIX . $channel, $message);
        }
    }

    public function redis(): RedisClient
    {
        return $this->redis;
    }
}
