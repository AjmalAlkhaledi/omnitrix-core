<?php

declare(strict_types=1);

namespace Omnitrix\Core\Database\Config;

/** Pool sizing and lifecycle limits (seconds; 0 disables a timeout). */
final readonly class PoolConfig
{
    public function __construct(
        public int $min,
        public int $max,
        public float $acquireTimeout,
        public float $idleTimeout,
        public float $maxLifetime,
        public float $validationInterval,
        public float $evictionInterval,
    ) {}
}
