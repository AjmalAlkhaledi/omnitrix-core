<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis;

use Omnitrix\Core\Cache\KeyValueStoreInterface;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Redis\Exception\CommandException;
use Omnitrix\Core\Redis\Exception\ConnectionException;
use Omnitrix\Core\Redis\Exception\RedisException;
use OpenSwoole\Coroutine;
use Redis;
use RedisCluster;
use RedisException as PhpRedisException;
use Throwable;

use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Coroutine-safe Redis access (a single server or a Redis Cluster): every command leases a
 * pooled connection for its duration.
 * Pipelines and MULTI/EXEC blocks hold one lease for the whole block; pub/sub uses a dedicated
 * connection that never enters the pool.
 */
final class RedisClient implements KeyValueStoreInterface
{
    private readonly RedisPool $pool;

    public function __construct(public readonly RedisConfig $config, ?RedisPool $pool = null)
    {
        $this->pool = $pool ?? new RedisPool($config);
    }

    public function pool(): RedisPool
    {
        return $this->pool;
    }

    /**
     * Runs `$fn` with a leased connection. Connection-class failures discard the connection.
     *
     * @template T
     * @param callable(Redis|RedisCluster): T $fn
     * @return T
     */
    public function withConnection(callable $fn, ?CancellationToken $token = null): mixed
    {
        $redis = $this->pool->acquire($token);
        $healthy = true;
        try {
            $redis->clearLastError();
            $result = $fn($redis);
            // phpredis reports server error replies (WRONGTYPE, syntax…) as a false return value
            // plus getLastError() instead of an exception; surface them.
            $error = $redis->getLastError();
            if (is_string($error) && $error !== '') {
                $redis->clearLastError();
                throw new CommandException($error);
            }

            return $result;
        } catch (PhpRedisException $e) {
            $classified = ErrorClassifier::classify($e);
            $healthy = !$classified instanceof ConnectionException;
            throw $classified;
        } catch (CommandException $e) {
            throw $e; // server-side error; the connection stays usable
        } catch (Throwable $e) {
            $healthy = !Coroutine::isCanceled();
            throw $e;
        } finally {
            $this->pool->release($redis, $healthy && !Coroutine::isCanceled());
        }
    }

    /**
     * Any command by name (`$redis->rawCommand`), for commands without a typed helper. Arguments
     * are sent as given: the key prefix is not applied. On a cluster the command goes to the node
     * serving its first argument.
     *
     * @param list<string|int|float> $args
     */
    public function command(string $name, array $args = []): mixed
    {
        return $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r instanceof RedisCluster
            ? $r->rawcommand(self::nodeOf($r, (string) ($args[0] ?? $name)), $name, ...$args)
            : $r->rawcommand($name, ...$args));
    }

    /**
     * Address of the master serving `$key` as given. Raw command arguments are sent without the
     * key prefix (as on a single server), while phpredis would route a key with the prefix, so
     * the node is looked up explicitly.
     *
     * @return array{string, int}
     */
    private static function nodeOf(RedisCluster $r, string $key): array
    {
        $address = null;
        foreach ($r->_masters() as $master) {
            $address = self::address($master);
            if ($address !== null) {
                break;
            }
        }
        if ($address === null) {
            throw new ConnectionException('The Redis Cluster has no reachable master');
        }
        $slot = $r->rawcommand($address, 'CLUSTER', 'KEYSLOT', $key);
        $ranges = $r->rawcommand($address, 'CLUSTER', 'SLOTS');
        foreach (is_array($ranges) ? $ranges : [] as $range) {
            if (is_array($range) && is_int($slot) && is_int($range[0] ?? null) && is_int($range[1] ?? null) && $slot >= $range[0] && $slot <= $range[1]) {
                return self::address($range[2] ?? null) ?? $address;
            }
        }

        return $address;
    }

    /** @return array{string, int}|null */
    private static function address(mixed $node): ?array
    {
        return is_array($node) && is_string($node[0] ?? null) && is_int($node[1] ?? null) ? [$node[0], $node[1]] : null;
    }

    // --- strings ----------------------------------------------------------------------------

    public function get(string $key): ?string
    {
        $value = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->get($key));

        return is_string($value) ? $value : null;
    }

    /** @param int|null $ttl seconds */
    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        return $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $ttl === null ? $r->set($key, $value) : $r->setex($key, $ttl, $value)) === true;
    }

    /** SET NX (returns false when the key exists). */
    public function setIfAbsent(string $key, string $value, ?int $ttl = null): bool
    {
        $options = $ttl === null ? ['NX'] : ['NX', 'EX' => $ttl];

        return $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->set($key, $value, $options)) === true;
    }

    /**
     * @param list<string> $keys
     * @return list<string|null> values in the order of `$keys`
     */
    public function mget(array $keys): array
    {
        $values = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->mget($keys));
        $out = [];
        if (is_array($values)) {
            foreach ($values as $value) {
                $out[] = is_string($value) ? $value : null;
            }
        }

        return $out;
    }

    public function delete(string ...$keys): int
    {
        if ($keys === []) {
            return 0;
        }
        $deleted = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->del(...$keys));

        return is_int($deleted) ? $deleted : 0;
    }

    public function exists(string $key): bool
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->exists($key));

        return (is_int($n) ? $n : 0) > 0;
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->expire($key, $seconds)) === true;
    }

    /** Remaining TTL in seconds, -1 without expiry, -2 when missing. */
    public function ttl(string $key): int
    {
        $ttl = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->ttl($key));

        return is_int($ttl) ? $ttl : -2;
    }

    public function increment(string $key, int $by = 1): int
    {
        $value = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $by === 1 ? $r->incr($key) : $r->incrBy($key, $by));

        return is_int($value) ? $value : 0;
    }

    public function decrement(string $key, int $by = 1): int
    {
        $value = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $by === 1 ? $r->decr($key) : $r->decrBy($key, $by));

        return is_int($value) ? $value : 0;
    }

    // --- hashes / lists / sets (common subset) ----------------------------------------------

    public function hget(string $key, string $field): ?string
    {
        $value = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hGet($key, $field));

        return is_string($value) ? $value : null;
    }

    public function hset(string $key, string $field, string $value): void
    {
        $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hSet($key, $field, $value));
    }

    /** @return array<string, string> */
    public function hgetall(string $key): array
    {
        $all = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->hGetAll($key));
        $out = [];
        if (is_array($all)) {
            foreach ($all as $field => $value) {
                if (is_string($value)) {
                    $out[(string) $field] = $value;
                }
            }
        }

        return $out;
    }

    public function lpush(string $key, string ...$values): int
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->lPush($key, ...$values));

        return is_int($n) ? $n : 0;
    }

    public function rpush(string $key, string ...$values): int
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->rPush($key, ...$values));

        return is_int($n) ? $n : 0;
    }

    public function lpop(string $key): ?string
    {
        $v = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->lPop($key));

        return is_string($v) ? $v : null;
    }

    public function llen(string $key): int
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->lLen($key));

        return is_int($n) ? $n : 0;
    }

    /**
     * Blocking pop (BLPOP) bounded by `$timeout` seconds; null when nothing arrived. The pooled
     * connection's read timeout must exceed `$timeout`, otherwise a TimeoutException is raised.
     *
     * @param list<string> $keys
     * @return array{string, string}|null [key, value]
     */
    public function blpop(array $keys, int $timeout): ?array
    {
        $result = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->blPop($keys, $timeout));
        if (is_array($result) && isset($result[0], $result[1]) && is_string($result[0]) && is_string($result[1])) {
            return [$result[0], $result[1]];
        }

        return null;
    }

    public function sadd(string $key, string ...$members): int
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->sAdd($key, ...$members));

        return is_int($n) ? $n : 0;
    }

    /** @return list<string> */
    public function smembers(string $key): array
    {
        $members = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->sMembers($key));
        $out = [];
        if (is_array($members)) {
            foreach ($members as $m) {
                if (is_string($m)) {
                    $out[] = $m;
                }
            }
        }

        return $out;
    }

    // --- pipelines / transactions / pub-sub -------------------------------------------------

    /**
     * Queues commands on one connection and sends them in one round trip.
     *
     * @param callable(Redis|RedisCluster): void $commands
     * @return list<mixed> replies in command order
     */
    public function pipeline(callable $commands): array
    {
        return $this->block($commands, Redis::PIPELINE);
    }

    /**
     * MULTI/EXEC transaction (atomic on the server).
     *
     * @param callable(Redis|RedisCluster): void $commands
     * @return list<mixed> replies in command order
     */
    public function transaction(callable $commands): array
    {
        return $this->block($commands, Redis::MULTI);
    }

    /**
     * Runs `$commands` against a MULTI or PIPELINE handle and returns the replies.
     *
     * @param callable(Redis|RedisCluster): void $commands
     * @return list<mixed>
     */
    private function block(callable $commands, int $mode): array
    {
        return $this->withConnection(static function (Redis|RedisCluster $r) use ($commands, $mode): array {
            // A cluster has no pipeline mode: MULTI/EXEC batches per node instead, atomic only for
            // keys of one node (give related keys one hash tag, e.g. `queue:{name}:…`).
            $handle = $r instanceof RedisCluster ? $r->multi() : $r->multi($mode);
            if (!$handle instanceof Redis && !$handle instanceof RedisCluster) {
                throw new CommandException('Could not start the pipeline/transaction');
            }
            try {
                $commands($handle);
                $replies = $handle->exec();
            } catch (Throwable $e) {
                try {
                    $handle->discard();
                } catch (Throwable) {
                    // connection state is unknown; the caller's exception matters
                }
                throw $e;
            }
            if (!is_array($replies)) {
                throw new CommandException('EXEC returned no replies (transaction aborted?)');
            }

            return array_values($replies);
        });
    }

    public function publish(string $channel, string $message): int
    {
        $n = $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r->publish($channel, $message));

        return is_int($n) ? $n : 0;
    }

    /**
     * Subscribes on a dedicated connection and delivers messages to `$handler` until it returns
     * false or `$token` is cancelled. Blocks the calling coroutine (run it in a task).
     *
     * @param list<string> $channels
     * @param callable(string, string): bool $handler receives (channel, message)
     */
    public function subscribe(array $channels, callable $handler, ?CancellationToken $token = null): void
    {
        $redis = $this->pool->connect();
        try {
            $redis->setOption(Redis::OPT_READ_TIMEOUT, '-1');
            $cid = Coroutine::getCid();
            $token?->attach($cid);
            $token?->onCancel(static function () use ($redis): void {
                try {
                    $redis->close();
                } catch (Throwable) {
                    // closing wakes the blocked reader
                }
            });
            try {
                $prefix = $this->config->prefix;
                $redis->subscribe($channels, static function (Redis|RedisCluster $r, string $channel, string $message) use ($handler, $channels, $prefix): void {
                    // phpredis applies OPT_PREFIX to channel names too; hand back the logical name.
                    if ($prefix !== '' && str_starts_with($channel, $prefix)) {
                        $channel = substr($channel, strlen($prefix));
                    }
                    if ($handler($channel, $message) === false) {
                        $r->unsubscribe($channels);
                    }
                });
            } finally {
                $token?->detach($cid);
            }
        } catch (PhpRedisException $e) {
            if ($token !== null && $token->isCancelled()) {
                return;
            }
            throw ErrorClassifier::classify($e, 'subscribe');
        } finally {
            try {
                $redis->close();
            } catch (Throwable) {
                // already closed
            }
        }
    }

    public function ping(): bool
    {
        return $this->withConnection(static fn(Redis|RedisCluster $r): mixed => $r instanceof RedisCluster ? ($r->ping('omnitrix:ping') !== false) : $r->ping()) === true;
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->pool->stats();
    }

    public function close(): void
    {
        $this->pool->close();
    }

    /** @param list<string> $keys */
    public function getMany(array $keys): array
    {
        return $this->mget($keys);
    }

    public function throwIfUnavailable(): void
    {
        if (!$this->ping()) {
            throw new RedisException('Redis did not answer PING');
        }
    }
}
