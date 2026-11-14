<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Pool;

/** Snapshot of pool state for metrics and tests. */
final readonly class PoolStats
{
    public function __construct(
        public int $size,
        public int $idle,
        public int $inUse,
        public int $waiting,
        public int $created,
        public int $destroyed,
        public int $acquired,
        public int $timeouts,
        public int $validationFailures,
        public float $totalWaitSeconds,
        public float $maxWaitSeconds,
    ) {}

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return [
            'size' => $this->size, 'idle' => $this->idle, 'in_use' => $this->inUse, 'waiting' => $this->waiting,
            'created' => $this->created, 'destroyed' => $this->destroyed, 'acquired' => $this->acquired,
            'timeouts' => $this->timeouts, 'validation_failures' => $this->validationFailures,
            'total_wait_seconds' => $this->totalWaitSeconds, 'max_wait_seconds' => $this->maxWaitSeconds,
        ];
    }
}
