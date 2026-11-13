<?php

declare(strict_types=1);

namespace Omnitrix\Core\Redis;

use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Pool\ClientPool;
use Omnitrix\Core\Pool\PoolTimeoutException;
use Omnitrix\Core\Redis\Exception\ConnectionException;
use Omnitrix\Core\Redis\Exception\TimeoutException;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use Redis;
use RedisException;
use Throwable;

use function max;

/**
 * Pool of phpredis connections for one worker. Each connection is used by one coroutine at a
 * time; phpredis under OpenSwoole runtime hooks suspends only that coroutine.
 */
final class RedisPool
{
    /** @var ClientPool<Redis> */
    private readonly ClientPool $pool;

    private ?int $evictionTimer = null;

    public function __construct(private readonly RedisConfig $config)
    {
        $this->pool = new ClientPool(
            $config->host . ':' . $config->port . '/' . $config->database,
            $config->poolMax,
            $config->poolAcquireTimeout,
            $config->poolIdleTimeout,
            fn(): Redis => $this->connect(),
            static function (Redis $redis): void {
                try {
                    $redis->close();
                } catch (Throwable) {
                    // already gone
                }
            },
            static function (Redis $redis): bool {
                try {
                    return $redis->ping() === true;
                } catch (Throwable) {
                    return false;
                }
            },
            $config->poolValidationInterval,
        );
    }

    /** Opens a fresh, authenticated connection outside the pool (pub/sub, blocking consumers). */
    public function connect(): Redis
    {
        $redis = new Redis();
        try {
            if (!$redis->connect($this->config->host, $this->config->port, $this->config->timeout, null, 0, $this->config->readTimeout)) {
                throw new ConnectionException("Could not connect to Redis at {$this->config->host}:{$this->config->port}");
            }
            if ($this->config->password !== null) {
                $auth = $this->config->username !== null ? [$this->config->username, $this->config->password] : $this->config->password;
                if ($redis->auth($auth) !== true) {
                    throw new ConnectionException('Redis authentication failed');
                }
            }
            if ($this->config->database !== 0 && $redis->select($this->config->database) !== true) {
                throw new ConnectionException("Could not select Redis database {$this->config->database}");
            }
            $redis->setOption(Redis::OPT_READ_TIMEOUT, (string) $this->config->readTimeout);
            if ($this->config->prefix !== '') {
                $redis->setOption(Redis::OPT_PREFIX, $this->config->prefix);
            }
        } catch (RedisException $e) {
            throw ErrorClassifier::classify($e, 'connect');
        }

        return $redis;
    }

    public function acquire(?CancellationToken $token = null): Redis
    {
        try {
            $redis = $this->pool->acquire($token);
        } catch (PoolTimeoutException $e) {
            throw new TimeoutException($e->getMessage(), 0, $e);
        }
        $this->startEviction();

        return $redis;
    }

    public function release(Redis $redis, bool $healthy = true): void
    {
        $this->pool->release($redis, $healthy);
    }

    /** @return array{size: int, idle: int, in_use: int, waiting: int, created: int, destroyed: int, timeouts: int, validation_failures: int} */
    public function stats(): array
    {
        return $this->pool->stats();
    }

    public function close(): void
    {
        if ($this->evictionTimer !== null) {
            Timers::clear($this->evictionTimer);
            $this->evictionTimer = null;
        }
        $this->pool->close();
    }

    private function startEviction(): void
    {
        if ($this->evictionTimer !== null || $this->config->poolIdleTimeout <= 0.0) {
            return;
        }
        $this->evictionTimer = Timers::tick(max(1000, (int) ($this->config->poolIdleTimeout * 500)), function (): void {
            $this->pool->evict();
        });
    }
}
