<?php

declare(strict_types=1);

namespace Omnitrix\Tests\Integration\RateLimit;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Facades\Facade;
use Omnitrix\RateLimit\RedisRateLimiter;
use Omnitrix\Tests\Support\Services;
use Omnitrix\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

use function uniqid;

/** Fixed-window limiting against a real Redis, with an explicit client and with the worker's client. */
#[CoversClass(RedisRateLimiter::class)]
final class RedisRateLimiterTest extends TestCase
{
    private RedisClient $redis;

    protected function setUp(): void
    {
        $this->redis = new RedisClient(RedisConfig::fromArray(['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'omnitrix-ratelimit-test:']));
    }

    protected function tearDown(): void
    {
        Facade::setContainer(null);
        $this->redis->close();
    }

    public function testExplicitClientCountsHitsWithinTheWindow(): void
    {
        $limiter = new RedisRateLimiter($this->redis);
        $key = uniqid('k', true);

        $first = $limiter->hit($key, 2, 60);
        $second = $limiter->hit($key, 2, 60);
        $third = $limiter->hit($key, 2, 60);

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed);
        self::assertSame(3, $limiter->attempts($key, 60));
        self::assertGreaterThan(0, $limiter->availableIn($key, 60));
        $limiter->reset($key, 60);
        self::assertSame(0, $limiter->attempts($key, 60));
        self::assertTrue($limiter->hit($key, 2, 60)->allowed, 'a reset key starts over');
        self::assertSame(0, $third->remaining);
        self::assertGreaterThanOrEqual(1, $third->retryAfter);
    }

    public function testWithoutClientUsesTheWorkerRedisClient(): void
    {
        $container = new Container();
        $container->instance(RedisClient::class, $this->redis);
        Facade::setContainer($container);

        $limiter = new RedisRateLimiter(); // created before any worker exists, as in an application bootstrap
        $key = uniqid('k', true);

        self::assertTrue($limiter->hit($key, 1, 60)->allowed);
        self::assertFalse($limiter->hit($key, 1, 60)->allowed);
    }
}
