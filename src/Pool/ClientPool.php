<?php

declare(strict_types=1);

namespace Omnitrix\Core\Pool;

use Closure;
use Omnitrix\Core\Concurrency\CancellationToken;
use Omnitrix\Core\Concurrency\CancelledException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Throwable;

use function count;
use function is_object;
use function max;
use function microtime;
use function spl_object_id;

/**
 * Bounded pool of reusable client objects (HTTP connections, Redis clients…) for one worker.
 * Clients are created lazily up to `max`; waiters block (cancellable) up to the acquisition
 * timeout and otherwise receive {@see PoolTimeoutException}. An optional validator runs on
 * checkout for clients idle longer than `validationInterval`; failing clients are replaced.
 *
 * @template T of object
 */
final class ClientPool
{
    private readonly Channel $idle;

    private int $size = 0;

    private int $inUse = 0;

    private int $waiting = 0;

    private int $created = 0;

    private int $destroyed = 0;

    private int $timeouts = 0;

    /** @var array<int, float> spl_object_id => last release time */
    private array $lastUsed = [];

    private int $validationFailures = 0;

    /** @var array<int, T> every live client by object id */
    private array $live = [];

    /**
     * @param Closure(): T         $factory
     * @param Closure(T): void     $destroyer
     * @param null|Closure(T): bool $validator returns false when the client must be replaced
     */
    public function __construct(
        public readonly string $key,
        private readonly int $max,
        private readonly float $acquireTimeout,
        private readonly float $idleTimeout,
        private readonly Closure $factory,
        private readonly Closure $destroyer,
        private readonly ?Closure $validator = null,
        private readonly float $validationInterval = 0.0,
    ) {
        $this->idle = new Channel(max(1, $max));
    }

    /** @return T */
    public function acquire(?CancellationToken $token = null): object
    {
        $token?->throwIfCancelled();
        while (true) {
            $client = $this->idle->isEmpty() ? false : $this->idle->pop(1.0);
            $fresh = false;
            if (!is_object($client)) {
                if ($this->size < $this->max) {
                    $this->size++;
                    $this->created++;
                    try {
                        $client = ($this->factory)();
                    } catch (Throwable $e) {
                        $this->size--;
                        throw $e;
                    }
                    $this->live[spl_object_id($client)] = $client;
                    $fresh = true;
                } else {
                    $client = $this->waitForIdle($token);
                }
            }
            /** @var T $client */
            if (!$fresh && !$this->validate($client)) {
                $this->destroy($client);
                continue;
            }
            $this->inUse++;
            unset($this->lastUsed[spl_object_id($client)]);

            return $client;
        }
    }

    /** @param T $client */
    private function validate(object $client): bool
    {
        if ($this->validator === null) {
            return true;
        }
        $idleFor = microtime(true) - ($this->lastUsed[spl_object_id($client)] ?? 0.0);
        if ($this->validationInterval > 0.0 && $idleFor < $this->validationInterval) {
            return true;
        }
        try {
            if (($this->validator)($client)) {
                return true;
            }
        } catch (Throwable) {
            // treated as a failed validation
        }
        $this->validationFailures++;

        return false;
    }

    /** @param T $client */
    public function release(object $client, bool $healthy = true): void
    {
        $this->inUse--;
        if (!$healthy || $this->idle->isFull()) {
            $this->destroy($client);

            return;
        }
        $this->lastUsed[spl_object_id($client)] = microtime(true);
        $this->idle->push($client, 1.0);
    }

    /** Closes idle clients unused for longer than the idle timeout. */
    public function evict(): int
    {
        if ($this->idleTimeout <= 0.0) {
            return 0;
        }
        $now = microtime(true);
        $keep = [];
        $evicted = 0;
        while (!$this->idle->isEmpty() && is_object($client = $this->idle->pop(1.0))) {
            /** @var T $client */
            $last = $this->lastUsed[spl_object_id($client)] ?? $now;
            if ($now - $last > $this->idleTimeout) {
                $this->destroy($client);
                $evicted++;
                continue;
            }
            $keep[] = $client;
        }
        foreach ($keep as $client) {
            $this->idle->push($client, 1.0);
        }

        return $evicted;
    }

    public function close(): void
    {
        if (Coroutine::getCid() < 1) {
            // Outside a coroutine (WorkerStop) channels are unusable: close tracked clients directly.
            foreach ($this->live as $client) {
                try {
                    ($this->destroyer)($client);
                } catch (Throwable) {
                    // already closed
                }
            }
            $this->destroyed += count($this->live);
            $this->live = [];
            $this->size = 0;

            return;
        }
        while (!$this->idle->isEmpty() && is_object($client = $this->idle->pop(1.0))) {
            /** @var T $client */
            $this->destroy($client);
        }
    }

    /** @return array{size: int, idle: int, in_use: int, waiting: int, created: int, destroyed: int, timeouts: int, validation_failures: int} */
    public function stats(): array
    {
        return ['size' => $this->size, 'idle' => $this->idle->length(), 'in_use' => $this->inUse, 'waiting' => $this->waiting, 'created' => $this->created, 'destroyed' => $this->destroyed, 'timeouts' => $this->timeouts, 'validation_failures' => $this->validationFailures];
    }

    /** @return T */
    private function waitForIdle(?CancellationToken $token): object
    {
        $cid = Coroutine::getCid();
        $token?->attach($cid);
        $this->waiting++;
        try {
            $client = $this->idle->pop(max(0.001, $this->acquireTimeout));
        } finally {
            $this->waiting--;
            $token?->detach($cid);
        }
        if (is_object($client)) {
            /** @var T $client */
            return $client;
        }
        if (Coroutine::isCanceled() || ($token !== null && $token->isCancelled())) {
            throw new CancelledException('Cancelled while waiting for an outbound connection');
        }
        $this->timeouts++;

        throw new PoolTimeoutException("No client for {$this->key} available within {$this->acquireTimeout}s ({$this->max} in use)");
    }

    /** @param T $client */
    private function destroy(object $client): void
    {
        unset($this->live[spl_object_id($client)]);
        $this->size--;
        $this->destroyed++;
        unset($this->lastUsed[spl_object_id($client)]);
        try {
            ($this->destroyer)($client);
        } catch (Throwable) {
            // closing a dead socket may fail
        }
    }
}
