<?php

declare(strict_types=1);

namespace Omnitrix\Core\Queue;

use InvalidArgumentException;
use Omnitrix\Core\Redis\RedisClient;
use Redis;
use RedisCluster;

use function is_array;
use function is_int;
use function is_string;
use function max;
use function microtime;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Reliable queue on Redis lists.
 *
 * Keys per queue `q`: `queue:q:ready` (list), `queue:q:delayed` (sorted set by due time),
 * `queue:q:processing:{consumer}` (list), `queue:q:dead` (list), `queue:consumer:{id}`
 * (heartbeat key); on a Redis Cluster the queue name is a hash tag (`queue:{q}:ready`). Reserving moves a job atomically from `ready` to the consumer's
 * processing list (BLMOVE), so a consumer that dies mid-job leaves the job recoverable:
 * {@see recover()} returns it to `ready` once the consumer's heartbeat has expired.
 * Delivery is at-least-once; handlers must be idempotent.
 */
final class RedisQueue
{
    private const string PROMOTE = <<<'LUA'
        local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, 100)
        for _, job in ipairs(due) do
            redis.call('ZREM', KEYS[1], job)
            redis.call('RPUSH', KEYS[2], job)
        end
        return #due
        LUA;

    /** Whether keys carry a per-queue hash tag (Redis Cluster): a queue's keys then share one slot. */
    private readonly bool $cluster;

    public function __construct(private readonly RedisClient $redis)
    {
        $this->cluster = $redis->config->isCluster();
    }

    /** Enqueues a job, optionally delayed by `$delay` seconds. */
    public function push(Job $job, float $delay = 0.0): void
    {
        self::assertQueueName($job->queue);
        if ($delay > 0.0) {
            $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->zAdd($this->keyOf($job->queue, 'delayed'), microtime(true) + $delay, $job->toJson()));

            return;
        }
        $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->lPush($this->keyOf($job->queue, 'ready'), $job->toJson()));
    }

    /**
     * Waits up to `$timeout` seconds (whole seconds, ≥ 1) for a job and moves it to the
     * consumer's processing list. Returns [job, raw] or null.
     *
     * @return array{Job, string}|null
     */
    public function reserve(string $queue, string $consumer, int $timeout = 1): ?array
    {
        $this->promoteDue($queue);
        $raw = $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->blmove($this->keyOf($queue, 'ready'), $this->keyOf($queue, 'processing:' . $consumer), 'RIGHT', 'LEFT', max(1, $timeout)));
        if (!is_string($raw)) {
            return null;
        }
        try {
            return [Job::fromJson($raw), $raw];
        } catch (InvalidArgumentException $e) {
            // poison message: park it in the dead letter list untouched
            $this->redis->transaction(function (Redis|RedisCluster $m) use ($queue, $consumer, $raw): void {
                $m->lrem($this->keyOf($queue, 'processing:' . $consumer), $raw, 1);
                $m->lPush($this->keyOf($queue, 'dead'), $raw);
            });

            return null;
        }
    }

    /** Removes a finished job from the processing list. */
    public function ack(string $queue, string $consumer, string $raw): void
    {
        $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->lrem($this->keyOf($queue, 'processing:' . $consumer), $raw, 1));
    }

    /** Re-schedules a failed job after `$delay` seconds (atomic move). */
    public function retry(string $queue, string $consumer, string $raw, Job $next, float $delay): void
    {
        $this->redis->transaction(function (Redis|RedisCluster $m) use ($queue, $consumer, $raw, $next, $delay): void {
            $m->lrem($this->keyOf($queue, 'processing:' . $consumer), $raw, 1);
            $m->zAdd($this->keyOf($queue, 'delayed'), microtime(true) + $delay, $next->toJson());
        });
    }

    /** Moves a job that exhausted its attempts to the dead letter list (atomic). */
    public function bury(string $queue, string $consumer, string $raw, Job $final): void
    {
        $this->redis->transaction(function (Redis|RedisCluster $m) use ($queue, $consumer, $raw, $final): void {
            $m->lrem($this->keyOf($queue, 'processing:' . $consumer), $raw, 1);
            $m->lPush($this->keyOf($queue, 'dead'), $final->toJson());
        });
    }

    /** Marks a consumer alive for `$ttl` seconds. */
    public function heartbeat(string $consumer, int $ttl): void
    {
        $this->redis->set('queue:consumer:' . $consumer, (string) microtime(true), max(1, $ttl));
    }

    public function forgetConsumer(string $consumer): void
    {
        $this->redis->delete('queue:consumer:' . $consumer);
    }

    /**
     * Returns jobs held by consumers whose heartbeat expired (crashed or killed) to `ready`.
     *
     * @return int jobs recovered
     */
    public function recover(string $queue): int
    {
        $prefix = $this->keyOf($queue, 'processing:');
        $recovered = 0;
        foreach ($this->processingKeys($queue) as $key) {
            $consumer = substr($key, strlen($prefix));
            if ($this->redis->exists('queue:consumer:' . $consumer)) {
                continue;
            }
            while (true) {
                $moved = $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->lMove($key, $this->keyOf($queue, 'ready'), 'RIGHT', 'LEFT'));
                if (!is_string($moved)) {
                    break;
                }
                $recovered++;
            }
        }

        return $recovered;
    }

    /** @return array{ready: int, delayed: int, processing: int, dead: int} */
    public function stats(string $queue): array
    {
        $processing = 0;
        foreach ($this->processingKeys($queue) as $key) {
            $processing += $this->redis->llen($key);
        }
        $delayed = $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->zCard($this->keyOf($queue, 'delayed')));

        return [
            'ready' => $this->redis->llen($this->keyOf($queue, 'ready')),
            'delayed' => is_int($delayed) ? $delayed : 0,
            'processing' => $processing,
            'dead' => $this->redis->llen($this->keyOf($queue, 'dead')),
        ];
    }

    /** @return list<Job> newest first */
    public function deadLetters(string $queue, int $limit = 100): array
    {
        $raw = $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->lrange($this->keyOf($queue, 'dead'), 0, max(0, $limit - 1)));
        $jobs = [];
        foreach (is_array($raw) ? $raw : [] as $item) {
            if (is_string($item)) {
                try {
                    $jobs[] = Job::fromJson($item);
                } catch (InvalidArgumentException) {
                    // unparsable poison message stays in the list for manual inspection
                }
            }
        }

        return $jobs;
    }

    /** Moves due delayed jobs to `ready` (atomic Lua script). */
    public function promoteDue(string $queue): int
    {
        $n = $this->redis->withConnection(fn(Redis|RedisCluster $r): mixed => $r->eval(self::PROMOTE, [$this->keyOf($queue, 'delayed'), $this->keyOf($queue, 'ready'), (string) microtime(true)], 2));

        return is_int($n) ? $n : 0;
    }

    /** Deletes every key of a queue (tests / administration). */
    public function purge(string $queue): void
    {
        $keys = [$this->keyOf($queue, 'ready'), $this->keyOf($queue, 'delayed'), $this->keyOf($queue, 'dead'), ...$this->processingKeys($queue)];
        $this->redis->delete(...$keys);
    }

    /** @return list<string> unprefixed processing list keys */
    private function processingKeys(string $queue): array
    {
        $route = $this->keyOf($queue, 'ready');

        return $this->redis->withConnection(function (Redis|RedisCluster $r) use ($queue, $route): array {
            $option = $r->getOption(Redis::OPT_PREFIX);
            $prefix = is_string($option) ? $option : '';
            $pattern = $prefix . $this->keyOf($queue, 'processing:') . '*';
            $keys = [];
            $iterator = null;
            do {
                // On a cluster every key of the queue is on the node owning its hash tag: scan there.
                $batch = $r instanceof RedisCluster ? $r->scan($iterator, $route, $pattern, 200) : $r->scan($iterator, $pattern, 200);
                foreach (is_array($batch) ? $batch : [] as $key) {
                    if (is_string($key)) {
                        $keys[] = $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                    }
                }
            } while ($iterator !== 0 && $iterator !== null);

            return $keys;
        });
    }

    /**
     * Key of a queue structure. On a Redis Cluster the queue name is a hash tag
     * (`queue:{q}:ready`), so BLMOVE, transactions and scripts over one queue's keys stay in one
     * slot; a single server keeps the plain names.
     */
    public static function key(string $queue, string $suffix, bool $cluster = false): string
    {
        return $cluster ? 'queue:{' . $queue . '}:' . $suffix : 'queue:' . $queue . ':' . $suffix;
    }

    private function keyOf(string $queue, string $suffix): string
    {
        return self::key($queue, $suffix, $this->cluster);
    }

    private static function assertQueueName(string $queue): void
    {
        if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $queue) !== 1) {
            throw new InvalidArgumentException("Invalid queue name '{$queue}'");
        }
    }
}
