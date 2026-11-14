<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use OpenSwoole\Coroutine;
use Throwable;

/**
 * Cooperative cancellation signal.
 *
 * Cancelling a token (1) flips it permanently, (2) interrupts every coroutine attached to it
 * that is suspended in a cancellable operation (`Coroutine::cancel()`: channel waits, sleeps,
 * timers, hooked sockets — on OpenSwoole 26.2; a coroutine busy on CPU is not
 * interrupted), (3) runs registered callbacks, (4) cancels child tokens. Code that performs
 * long or repeated work should call {@see throwIfCancelled()} at safe points.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    private ?string $reason = null;

    /** @var list<callable(string|null): void> */
    private array $callbacks = [];

    /** @var array<int, true> */
    private array $coroutines = [];

    /** @var list<CancellationToken> */
    private array $children = [];

    public static function none(): self
    {
        return new self();
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /** @throws CancelledException */
    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new CancelledException($this->reason ?? 'Operation cancelled');
        }
    }

    /** Registers a callback; runs immediately if already cancelled. @param callable(string|null): void $callback */
    public function onCancel(callable $callback): void
    {
        if ($this->cancelled) {
            $callback($this->reason);

            return;
        }
        $this->callbacks[] = $callback;
    }

    /** Attaches a coroutine that will be interrupted on cancel. */
    public function attach(int $cid): void
    {
        if ($this->cancelled) {
            Coroutine::cancel($cid);

            return;
        }
        $this->coroutines[$cid] = true;
    }

    public function detach(int $cid): void
    {
        unset($this->coroutines[$cid]);
    }

    /** A token that is cancelled when this one is (but not the other way round). */
    public function createChild(): self
    {
        $child = new self();
        if ($this->cancelled) {
            $child->cancel($this->reason);
        } else {
            $this->children[] = $child;
        }

        return $child;
    }

    /** Idempotent. */
    public function cancel(?string $reason = null): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        $this->reason = $reason;

        $coroutines = $this->coroutines;
        $this->coroutines = [];
        foreach ($coroutines as $cid => $_) {
            if (Coroutine::exists($cid) && $cid !== Coroutine::getCid()) {
                Coroutine::cancel($cid);
            }
        }

        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            try {
                $callback($reason);
            } catch (Throwable) {
                // cancellation must never fail half-way; callbacks are best-effort notifications
            }
        }

        $children = $this->children;
        $this->children = [];
        foreach ($children as $child) {
            $child->cancel($reason);
        }
    }
}
