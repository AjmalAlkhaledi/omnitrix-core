<?php

declare(strict_types=1);

namespace Omnitrix\RateLimit;

use OpenSwoole\Table;

use function crc32;
use function dechex;
use function max;
use function md5;
use function strlen;
use function time;

/**
 * Fixed-window limiter in OpenSwoole shared memory: shared by every worker of one server (create
 * it before the server starts), no network round trip. Keys are hashed into a bounded table;
 * when the table is full, new keys are allowed (fail-open) rather than blocking traffic.
 */
final class TableRateLimiter implements RateLimiterInterface
{
    private readonly Table $table;

    public function __construct(int $maxKeys = 65536)
    {
        $this->table = new Table($maxKeys);
        $this->table->column('window', Table::TYPE_INT);
        $this->table->column('count', Table::TYPE_INT);
        $this->table->create();
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $windowSeconds = max(1, $windowSeconds);
        $now = time();
        $window = (int) ($now / $windowSeconds);
        $id = dechex(crc32($key)) . ':' . $key;
        $id = strlen($id) > 60 ? dechex(crc32($key)) . ':' . md5($key) : $id;

        $stored = $this->table->get($id, 'window');
        if ($stored !== $window) {
            // New window: reset. A concurrent reset races benignly (both write the same values).
            if ($this->table->set($id, ['window' => $window, 'count' => 0]) === false) {
                return new RateLimitResult(true, $limit, $limit, 0); // table full: fail open
            }
        }
        $count = $this->table->incr($id, 'count');
        $retryAfter = ($window + 1) * $windowSeconds - $now;

        return new RateLimitResult($count <= $limit, $limit, max(0, $limit - $count), max(1, $retryAfter));
    }
}
