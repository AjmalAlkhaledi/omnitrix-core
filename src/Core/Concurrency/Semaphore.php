<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use InvalidArgumentException;
use LogicException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

use function max;

/**
 * Counting semaphore for bounding concurrency across coroutines (permits are channel tokens).
 */
final class Semaphore
{
    private readonly Channel $permits;

    private int $inUse = 0;

    private int $peak = 0;

    public function __construct(public readonly int $limit)
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Semaphore limit must be >= 1');
        }
        $this->permits = new Channel($limit);
        for ($i = 0; $i < $limit; $i++) {
            $this->permits->push(true);
        }
    }

    /**
     * Waits for a permit. Throws {@see TimeoutException} after `$timeout` seconds or
     * {@see CancelledException} if the token is cancelled (or the coroutine is cancelled)
     * while waiting.
     */
    public function acquire(?float $timeout = null, ?CancellationToken $token = null): void
    {
        $token?->throwIfCancelled();
        $cid = Coroutine::getCid();
        $token?->attach($cid);
        try {
            $ok = $this->permits->pop($timeout === null ? -1 : max(0.001, $timeout));
        } finally {
            $token?->detach($cid);
        }
        if ($ok !== true) {
            if (Coroutine::isCanceled() || ($token !== null && $token->isCancelled())) {
                throw new CancelledException('Cancelled while waiting for a permit');
            }
            throw new TimeoutException("No permit available within {$timeout}s (limit {$this->limit})");
        }
        $this->inUse++;
        $this->peak = max($this->peak, $this->inUse);
    }

    public function release(): void
    {
        if ($this->inUse === 0) {
            throw new LogicException('Semaphore released more times than acquired');
        }
        $this->inUse--;
        $this->permits->push(true);
    }

    /** Runs `$fn` while holding a permit. */
    public function withPermit(callable $fn, ?float $timeout = null, ?CancellationToken $token = null): mixed
    {
        $this->acquire($timeout, $token);
        try {
            return $fn();
        } finally {
            $this->release();
        }
    }

    public function available(): int
    {
        return $this->permits->length();
    }

    public function inUse(): int
    {
        return $this->inUse;
    }

    public function peak(): int
    {
        return $this->peak;
    }
}
