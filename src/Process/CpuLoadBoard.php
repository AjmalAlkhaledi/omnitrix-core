<?php

declare(strict_types=1);

namespace Omnitrix\Core\Process;

use OpenSwoole\Table;

use function count;
use function is_int;
use function max;
use function random_int;

use const PHP_INT_MAX;

/**
 * Shared-memory view of which CPU workers are busy, visible to every HTTP worker. Created in
 * the master before forking (an OpenSwoole Table), so all processes see the same counters and
 * concurrent jobs from different HTTP workers spread across CPU workers.
 *
 * @internal
 */
final class CpuLoadBoard
{
    private readonly Table $table;

    public function __construct(private readonly int $workers)
    {
        $this->table = new Table(max(8, $workers * 2));
        $this->table->column('load', Table::TYPE_INT);
        $this->table->create();
        for ($i = 0; $i < $workers; $i++) {
            $this->table->set((string) $i, ['load' => 0]);
        }
    }

    /** Least-loaded worker; ties broken randomly so callers do not all pick worker 0. */
    public function pick(): int
    {
        $best = [];
        $min = PHP_INT_MAX;
        for ($i = 0; $i < $this->workers; $i++) {
            $load = $this->load($i);
            if ($load < $min) {
                $min = $load;
                $best = [$i];
            } elseif ($load === $min) {
                $best[] = $i;
            }
        }

        return $best[random_int(0, count($best) - 1)] ?? 0;
    }

    public function increment(int $index): void
    {
        $this->table->incr((string) $index, 'load');
    }

    public function decrement(int $index): void
    {
        $this->table->decr((string) $index, 'load');
    }

    /** Resets a worker's counter (after it was killed or restarted). */
    public function reset(int $index): void
    {
        $this->table->set((string) $index, ['load' => 0]);
    }

    public function load(int $index): int
    {
        $value = $this->table->get((string) $index, 'load');

        return is_int($value) ? $value : 0;
    }
}
