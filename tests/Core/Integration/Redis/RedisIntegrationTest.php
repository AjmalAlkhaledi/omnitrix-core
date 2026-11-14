<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Integration\Redis;

use Omnitrix\Core\Cache\Cache;
use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Redis\Exception\CommandException;
use Omnitrix\Core\Redis\Exception\ConnectionException;
use Omnitrix\Core\Redis\Exception\TimeoutException;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\ProcessRunner;
use Omnitrix\Core\Tests\Support\Services;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Redis;

use function dirname;
use function hrtime;
use function uniqid;

/** Redis module acceptance against the local Redis 8 started by bin/services. */
#[CoversNothing]
final class RedisIntegrationTest extends TestCase
{
    private ?RedisClient $client = null;

    /** @param array<string, mixed> $overrides */
    private function client(array $overrides = []): RedisClient
    {
        $this->client = new RedisClient(RedisConfig::fromArray($overrides + ['host' => Services::redisHost(), 'port' => Services::redisPort(), 'prefix' => 'omnitrix-test:', 'read_timeout' => 3.0, 'pool' => ['max' => 8, 'acquire_timeout' => 1.0, 'validation_interval' => 0.0]]));
        try {
            $this->client->ping();
        } catch (ConnectionException $e) {
            Services::failUnreachable('Redis', $e->getMessage());
        }

        return $this->client;
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) {
            try {
                $this->client->withConnection(static fn(Redis $r) => $r->flushDB());
            } catch (ConnectionException) {
            }
            $this->client->close();
            $this->client = null;
        }
        parent::tearDown();
    }

    public function testBasicCommandsExpiryAndCounters(): void
    {
        $r = $this->client();
        self::assertTrue($r->set('k', 'v'));
        self::assertSame('v', $r->get('k'));
        self::assertNull($r->get('missing'));
        self::assertTrue($r->exists('k'));
        self::assertTrue($r->set('t', 'x', 10));
        self::assertGreaterThan(0, $r->ttl('t'));
        self::assertTrue($r->expire('k', 20));
        self::assertLessThanOrEqual(20, $r->ttl('k'));
        self::assertSame(1, $r->increment('c'));
        self::assertSame(6, $r->increment('c', 5));
        self::assertSame(5, $r->decrement('c'));
        self::assertTrue($r->setIfAbsent('nx', '1'));
        self::assertFalse($r->setIfAbsent('nx', '2'));
        self::assertSame(['v', null, '1'], $r->mget(['k', 'missing', 'nx']));
        $r->hset('h', 'f', '1');
        self::assertSame('1', $r->hget('h', 'f'));
        self::assertSame(['f' => '1'], $r->hgetall('h'));
        self::assertSame(2, $r->rpush('l', 'a', 'b'));
        self::assertSame('a', $r->lpop('l'));
        self::assertSame(1, $r->llen('l'));
        self::assertSame(2, $r->sadd('s', 'x', 'y'));
        self::assertEqualsCanonicalizing(['x', 'y'], $r->smembers('s'));
        self::assertSame(3, $r->delete('k', 't', 'nx'));
        self::assertSame('hi', $r->command('ECHO', ['hi']), 'raw commands pass through');
        try {
            $r->lpush('h', 'wrong');
            self::fail('WRONGTYPE expected');
        } catch (CommandException $e) {
            self::assertStringContainsString('WRONGTYPE', $e->getMessage());
        }
        self::assertSame('1', $r->hget('h', 'f'), 'connection still usable after a command error');
        self::assertSame(0, $r->stats()['in_use']);
    }

    public function testPipelineAndTransactionRoundTrips(): void
    {
        $r = $this->client();
        $t = hrtime(true);
        $replies = $r->pipeline(static function (Redis $p): void {
            for ($i = 0; $i < 1000; $i++) {
                $p->set("p{$i}", (string) $i);
            }
            $p->get('p999');
        });
        self::assertCount(1001, $replies);
        self::assertSame('999', $replies[1000]);
        self::assertLessThan(0.5, (hrtime(true) - $t) / 1e9, '1000 commands in one round trip');

        $replies = $r->transaction(static function (Redis $m): void {
            $m->incr('tx');
            $m->incr('tx');
            $m->get('tx');
        });
        self::assertSame([1, 2, '2'], $replies);
        self::assertSame(0, $r->stats()['in_use']);
    }

    public function testConcurrentBlockingPopsOverlapAndPubSubDelivers(): void
    {
        $r = $this->client();
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[$i] = static fn(): ?array => $r->blpop(["empty{$i}"], 1);
        }
        $tasks['ticker'] = static function (): int {
            $n = 0;
            for ($i = 0; $i < 10; $i++) {
                Sleep::milliseconds(50);
                $n++;
            }

            return $n;
        };
        [$results, $elapsed] = self::runConcurrently($tasks);
        self::assertLessThan(1.8, $elapsed, "5 × BLPOP(1s) took {$elapsed}s; they must overlap");
        self::assertSame(10, $results['ticker'], 'the loop kept running during blocking pops');
        self::assertNull($results[0]);

        $token = new CancellationToken();
        $received = [];
        $outcomes = Async::allSettled([
            'subscriber' => static function () use ($r, $token, &$received): string {
                $r->subscribe(['news'], static function (string $channel, string $message) use (&$received): bool {
                    $received[] = $channel . ':' . $message;

                    return $message !== 'stop';
                }, $token);

                return 'done';
            },
            'publisher' => static function () use ($r): int {
                Sleep::milliseconds(200);
                $n = $r->publish('news', 'hello');
                $r->publish('news', 'stop');

                return $n;
            },
        ], timeout: 5.0);
        self::assertTrue($outcomes['subscriber']->fulfilled, (string) $outcomes['subscriber']->reason?->getMessage());
        self::assertSame(1, $outcomes['publisher']->value, 'one subscriber received the publish');
        self::assertSame(['news:hello', 'news:stop'], $received);
        self::assertSame(0, $r->stats()['in_use'], 'pub/sub never uses pooled connections');
    }

    public function testReadTimeoutPoolExhaustionAndFailureHandling(): void
    {
        $r = $this->client(['read_timeout' => 0.5, 'pool' => ['max' => 1, 'acquire_timeout' => 0.3, 'validation_interval' => 0.0]]);
        $t = hrtime(true);
        try {
            $r->blpop(['never'], 5);
            self::fail('read timeout expected');
        } catch (TimeoutException) {
        }
        self::assertLessThan(1.5, (hrtime(true) - $t) / 1e9);
        self::assertSame(1, $r->stats()['destroyed'], 'timed-out connection discarded');
        self::assertSame('ok', $r->command('ECHO', ['ok']), 'a fresh connection serves the next command');

        [$results] = self::runConcurrently([
            'holder' => static fn(): mixed => $r->withConnection(static function (Redis $c): string {
                Sleep::milliseconds(600);

                return 'held';
            }),
            'waiter' => static function () use ($r): string {
                Sleep::milliseconds(50);
                try {
                    return $r->get('x') ?? 'nil';
                } catch (TimeoutException $e) {
                    return 'pool-timeout';
                }
            },
        ]);
        self::assertSame('pool-timeout', $results['waiter'], 'pool max=1 is enforced with a bounded wait');

        $down = new RedisClient(RedisConfig::fromArray(['host' => '127.0.0.1', 'port' => 63799, 'timeout' => 0.5]));
        $t = hrtime(true);
        try {
            $down->get('x');
            self::fail('closed port must fail');
        } catch (ConnectionException) {
        }
        self::assertLessThan(2.0, (hrtime(true) - $t) / 1e9);
        $down->close();
    }

    public function testRestartRecovery(): void
    {
        $r = $this->client();
        self::assertTrue($r->set('persist', '1'));
        $restart = ProcessRunner::command([dirname(__DIR__, 4) . '/bin/services', 'restart-redis']);
        self::assertSame(0, $restart->wait(60.0), $restart->stderr());
        $recovered = null;
        for ($i = 0; $i < 20 && $recovered === null; $i++) {
            try {
                $recovered = $r->command('ECHO', ['back']);
            } catch (ConnectionException) {
                Sleep::milliseconds(250);
            }
        }
        self::assertSame('back', $recovered, 'commands succeed again after the server restarted');
        self::assertSame(0, $r->stats()['in_use']);
    }

    public function testCacheOverRedisRoundTrip(): void
    {
        $cache = new Cache($this->client(), 'cache-it:', 30);
        $key = 'user:' . uniqid();
        self::assertTrue($cache->set($key, ['id' => 7, 'roles' => ['admin']]));
        self::assertSame(['id' => 7, 'roles' => ['admin']], $cache->get($key));
        self::assertSame(['id' => 7, 'roles' => ['admin']], $cache->remember($key, 30, static fn(): string => 'not used'));
        self::assertSame(2, $cache->increment('visits', 2));
        self::assertLessThanOrEqual(30, $cache->ttl($key));
        self::assertSame(1, $cache->delete($key));
    }
}
