<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Database;

use Closure;
use Omnitrix\Core\Concurrency\Async;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\CancelledException;
use Omnitrix\Core\Database\Config\PoolConfig;
use Omnitrix\Core\Database\Contracts\ConnectionInterface;
use Omnitrix\Core\Database\Exception\ConnectionException;
use Omnitrix\Core\Database\Exception\OwnershipViolationException;
use Omnitrix\Core\Database\Exception\PoolExhaustedException;
use Omnitrix\Core\Database\Pool\ConnectionPool;
use Omnitrix\Core\Database\Pool\PooledConnection;
use Omnitrix\Core\Database\Pool\PoolStats;
use Omnitrix\Core\Runtime\Coroutine\Sleep;
use Omnitrix\Core\Tests\Support\FakeConnection;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\Attributes\CoversClass;

use function count;
use function hrtime;
use function max;

#[CoversClass(ConnectionPool::class)]
#[CoversClass(PooledConnection::class)]
#[CoversClass(PoolStats::class)]
#[CoversClass(PoolExhaustedException::class)]
#[CoversClass(OwnershipViolationException::class)]
final class ConnectionPoolTest extends TestCase
{
    private int $sequence = 0;

    private ?ConnectionPool $pool = null;

    protected function setUp(): void
    {
        parent::setUp();
        FakeConnection::$created = 0;
        FakeConnection::$closed = 0;
    }

    protected function tearDown(): void
    {
        $this->pool?->close(1.0);
        $this->pool = null;
        parent::tearDown();
    }

    /** @param null|Closure(): ConnectionInterface $factory */
    private function pool(int $min = 0, int $max = 3, float $acquireTimeout = 0.2, float $idleTimeout = 0.0, float $maxLifetime = 0.0, float $validationInterval = 30.0, float $latency = 0.0, ?Closure $factory = null): ConnectionPool
    {
        $config = new PoolConfig($min, $max, $acquireTimeout, $idleTimeout, $maxLifetime, $validationInterval, 3600.0);
        $this->pool = new ConnectionPool('test', $config, $factory ?? fn(): FakeConnection => new FakeConnection('c' . ++$this->sequence, $latency));

        return $this->pool;
    }

    public function testBoundedSizeExhaustionAndRelease(): void
    {
        $pool = $this->pool(max: 2, acquireTimeout: 0.1);
        $a = $pool->acquire();
        $b = $pool->acquire();
        self::assertSame(2, $pool->stats()->size);
        self::assertSame(2, $pool->stats()->inUse);

        $t = hrtime(true);
        try {
            $pool->acquire();
            self::fail('third acquire must fail');
        } catch (PoolExhaustedException $e) {
            self::assertStringContainsString('2 connections in use', $e->getMessage());
        }
        self::assertGreaterThanOrEqual(0.09, (hrtime(true) - $t) / 1e9, 'waited for the acquisition timeout');
        self::assertSame(1, $pool->stats()->timeouts);

        $a->release();
        $c = $pool->acquire();
        self::assertSame($a->id(), $c->id(), 'released connection is reused');
        self::assertSame(2, FakeConnection::$created, 'no connection beyond max was created');
        $b->release();
        $c->release();
        self::assertSame(2, $pool->stats()->idle);
    }

    public function testWaitersAreServedWhenConnectionsReturn(): void
    {
        $pool = $this->pool(max: 1, acquireTimeout: 2.0);
        $first = $pool->acquire();
        $order = [];
        [$results, $elapsed] = self::runConcurrently([
            'waiter' => static function () use ($pool, &$order): string {
                $c = $pool->acquire();
                $order[] = 'acquired';
                $c->release();

                return $c->id();
            },
            'releaser' => static function () use ($first, &$order): void {
                Sleep::milliseconds(100);
                $order[] = 'released';
                $first->release();
            },
        ]);
        self::assertSame(['released', 'acquired'], $order);
        self::assertSame($first->id(), $results['waiter']);
        self::assertGreaterThanOrEqual(0.09, $elapsed);
        self::assertLessThan(0.5, $elapsed, 'the waiter woke up immediately on release, not at the timeout');
    }

    public function testConcurrentLeasesNeverShareAConnection(): void
    {
        $pool = $this->pool(max: 4, latency: 0.02);
        $tasks = [];
        for ($i = 0; $i < 12; $i++) {
            $tasks[] = static function () use ($pool): string {
                return $pool->withConnection(static function (PooledConnection $c): string {
                    $c->select('select 1');
                    $c->select('select 2');

                    return $c->id();
                }, 5.0);
            };
        }
        [$ids, $elapsed] = self::runConcurrently($tasks);
        self::assertLessThanOrEqual(4, count(array_unique($ids)));
        self::assertSame(4, max(FakeConnection::$created, 0), 'pool grew to max under load, not beyond');
        self::assertSame(0, $pool->stats()->inUse);
        self::assertSame(12, $pool->stats()->acquired);
        self::assertLessThan(0.3, $elapsed, '12 × 40 ms of latency over 4 lanes overlap');
    }

    public function testOwnershipIsEnforcedAcrossCoroutinesAndAfterRelease(): void
    {
        $pool = $this->pool();
        $lease = $pool->acquire();
        $error = new Channel(1);
        Coroutine::create(static function () use ($lease, $error): void {
            try {
                $lease->select('select 1');
                $error->push('no error');
            } catch (OwnershipViolationException $e) {
                $error->push($e->getMessage());
            }
        });
        self::assertStringContainsString('belongs to coroutine', self::string($error->pop(1.0)));

        $lease->release();
        try {
            $lease->execute('update');
            self::fail('use after release accepted');
        } catch (OwnershipViolationException $e) {
            self::assertStringContainsString('already released', $e->getMessage());
        }
        $lease->release(); // idempotent
        self::assertSame(1, $pool->stats()->idle);
    }

    public function testBrokenOrInTransactionConnectionsAreDiscardedOnRelease(): void
    {
        $pool = $this->pool(max: 2);
        $a = $pool->acquire();
        $a->markBroken();
        $a->release();
        self::assertSame(1, FakeConnection::$closed, 'broken connection destroyed');
        self::assertSame(0, $pool->stats()->size);

        $b = $pool->acquire();
        $b->beginTransaction();
        $b->release();
        self::assertSame(2, FakeConnection::$closed, 'connection leaked mid-transaction is destroyed, never reused');
        self::assertSame(0, $pool->stats()->idle);
        self::assertSame(['BEGIN', 'ROLLBACK'], ($b->inner() instanceof FakeConnection) ? $b->inner()->statements : []);
    }

    public function testValidationPingsIdleConnectionsAndReplacesDeadOnes(): void
    {
        $pool = $this->pool(max: 2, validationInterval: 1.0);
        $a = $pool->acquire();
        $inner = $a->inner();
        self::assertInstanceOf(FakeConnection::class, $inner);
        $a->release();

        $b = $pool->acquire();
        self::assertSame(0, $inner->pings, 'recently used connections are not pinged');
        $b->release();

        $inner->ageIdle(5.0);
        $inner->pingResult = false;
        $c = $pool->acquire();
        self::assertSame(1, $inner->pings, 'idle connection was validated');
        self::assertNotSame($inner->id, $c->id(), 'dead connection replaced by a fresh one');
        self::assertSame(1, $pool->stats()->validationFailures);
        $c->release();
    }

    public function testEvictionRespectsIdleTimeoutLifetimeAndMinimum(): void
    {
        $pool = $this->pool(min: 1, max: 3, idleTimeout: 1.0, maxLifetime: 0.0);
        $leases = [$pool->acquire(), $pool->acquire(), $pool->acquire()];
        $inners = [];
        foreach ($leases as $lease) {
            $inner = $lease->inner();
            self::assertInstanceOf(FakeConnection::class, $inner);
            $inners[] = $inner;
            $lease->release();
        }
        foreach ($inners as $inner) {
            $inner->ageIdle(10.0);
        }
        self::assertSame(2, $pool->evict(), 'idle connections evicted down to min');
        self::assertSame(1, $pool->stats()->size);

        $pool2 = $this->pool(min: 0, max: 2, maxLifetime: 0.001);
        $x = $pool2->acquire();
        $x->release();
        Sleep::milliseconds(5);
        self::assertSame(1, $pool2->evict(), 'connections past max_lifetime are recycled');
    }

    public function testWarmUpCreatesMinimumAndFactoryFailuresSurface(): void
    {
        $pool = $this->pool(min: 2, max: 4);
        $pool->warmUp();
        self::assertSame(2, $pool->stats()->size);
        self::assertSame(2, $pool->stats()->idle);

        $failing = $this->pool(max: 2, factory: static function (): ConnectionInterface {
            throw new ConnectionException('db down');
        });
        try {
            $failing->acquire();
            self::fail('factory failure must surface');
        } catch (ConnectionException $e) {
            self::assertSame('db down', $e->getMessage());
        }
        self::assertSame(0, $failing->stats()->size, 'the reserved slot was given back');
    }

    public function testAcquireIsCancellableWhileWaiting(): void
    {
        $pool = $this->pool(max: 1, acquireTimeout: 5.0);
        $held = $pool->acquire();
        $token = new CancellationToken();
        $t = hrtime(true);
        try {
            Async::all(['wait' => static fn(CancellationToken $child) => $pool->acquire(5.0, $child), 'cancel' => static function () use ($token): void {
                Sleep::milliseconds(50);
                $token->cancel('request aborted');
            }], parent: $token);
            self::fail('expected cancellation');
        } catch (CancelledException) {
        }
        self::assertLessThan(1.0, (hrtime(true) - $t) / 1e9, 'waiter was interrupted, not left until the timeout');
        self::assertSame(0, $pool->stats()->waiting);
        $held->release();
    }

    public function testCloseDrainsAndRefusesNewLeases(): void
    {
        $pool = $this->pool(max: 2);
        $a = $pool->acquire();
        $b = $pool->acquire();
        $b->release();
        Coroutine::create(static function () use ($a): void {
            Sleep::milliseconds(50);
            $a->release();
        });
        self::assertSame(0, $pool->close(2.0), 'all leases returned before close finished');
        self::assertTrue($pool->isClosed());
        self::assertSame(2, FakeConnection::$closed);
        $this->expectException(ConnectionException::class);
        $pool->acquire();
    }
}
