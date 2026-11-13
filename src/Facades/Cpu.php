<?php

declare(strict_types=1);

namespace Omnitrix\Facades;

use Omnitrix\Core\Process\CpuPool;

/**
 * CPU worker pool (see docs/process-pool.md).
 *
 * ```php
 * $report = Cpu::run('render-report', ['month' => '2026-10'], timeout: 20);
 * ```
 */
final class Cpu extends Facade
{
    public static function pool(): CpuPool
    {
        return self::resolve(CpuPool::class);
    }

    /** @param array<mixed> $args */
    public static function run(string $job, array $args = [], ?float $timeout = null): mixed
    {
        return self::pool()->run($job, $args, $timeout);
    }
}
