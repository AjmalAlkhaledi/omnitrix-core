<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Facades\Redis as RedisFacade;
use Redis;

use function is_array;
use function is_int;
use function max;
use function time;

/**
 * Fixed-window limiter shared by every worker and server (INCR + EXPIRE in one Lua call, so the
 * window is created atomically with its first hit).
 *
 * Without an explicit client the limiter uses the worker's Redis client (the `redis`
 * configuration section), resolved on first use, so it can be created before `run()`.
 */
final class RedisRateLimiter implements RateLimiterInterface
{
    private const string SCRIPT = <<<'LUA'
        local n = redis.call('INCR', KEYS[1])
        if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end
        return {n, redis.call('TTL', KEYS[1])}
        LUA;

    public function __construct(private readonly ?RedisClient $redis = null, private readonly string $prefix = 'ratelimit:') {}

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $window = (int) (time() / max(1, $windowSeconds));
        $redis = $this->redis ?? RedisFacade::client();
        $reply = $redis->withConnection(fn(Redis $r): mixed => $r->eval(self::SCRIPT, [$this->prefix . $key . ':' . $window, (string) $windowSeconds], 1));
        $count = is_array($reply) && is_int($reply[0] ?? null) ? $reply[0] : $limit + 1;
        $ttl = is_array($reply) && is_int($reply[1] ?? null) ? $reply[1] : $windowSeconds;

        return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), max(1, $ttl));
    }
}
