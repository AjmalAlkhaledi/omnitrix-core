<?php

declare(strict_types=1);

namespace Omnitrix\Core\Concurrency;

use Throwable;

/** Result of one task in {@see Async::allSettled()}: fulfilled with a value or rejected with a throwable. */
final readonly class Outcome
{
    private function __construct(public bool $fulfilled, public mixed $value, public ?Throwable $reason, public bool $cancelled) {}

    public static function fulfilled(mixed $value): self
    {
        return new self(true, $value, null, false);
    }

    public static function rejected(Throwable $reason, bool $cancelled = false): self
    {
        return new self(false, null, $reason, $cancelled);
    }

    public function isRejected(): bool
    {
        return !$this->fulfilled;
    }

    /** The value, or throws the rejection reason. */
    public function get(): mixed
    {
        if (!$this->fulfilled) {
            throw $this->reason ?? new CancelledException('rejected');
        }

        return $this->value;
    }
}
