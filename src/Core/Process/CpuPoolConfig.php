<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

use InvalidArgumentException;

use function rtrim;
use function sys_get_temp_dir;

/** CPU pool settings. */
final readonly class CpuPoolConfig
{
    public function __construct(
        /** Number of CPU worker processes (≈ cores reserved for CPU work). */
        public int $workers = 2,
        /** Default per-job timeout (seconds, includes queueing). */
        public float $timeout = 30.0,
        /** Per HTTP worker: maximum jobs submitted at once (backpressure). */
        public int $maxPending = 64,
        /** Directory for the unix sockets; must be private to the service user. */
        public string $socketDirectory = '',
        /** Distinguishes several servers on one host (defaults to the HTTP port). */
        public string $name = 'default',
    ) {
        if ($workers < 1 || $workers > 256) {
            throw new InvalidArgumentException('cpu.workers must be between 1 and 256');
        }
        if ($timeout <= 0.0) {
            throw new InvalidArgumentException('cpu.timeout must be > 0');
        }
        if ($maxPending < 1) {
            throw new InvalidArgumentException('cpu.max_pending must be >= 1');
        }
    }

    public function socketPath(int $index): string
    {
        $dir = $this->socketDirectory === '' ? sys_get_temp_dir() : rtrim($this->socketDirectory, '/');

        return "{$dir}/omnitrix-cpu-{$this->name}-{$index}.sock";
    }
}
