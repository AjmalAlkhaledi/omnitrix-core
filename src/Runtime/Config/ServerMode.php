<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Config;

use OpenSwoole\Server;

/**
 * OpenSwoole process model.
 *
 * - Process (default): master reactor threads own connections and dispatch to workers; a worker
 *   crash does not drop its connections and a SIGTERM shutdown drains in-flight requests
 *   (bounded by max_wait_time).
 * - Simple: each worker accepts connections directly (slightly lower latency; with >1 worker a
 *   manager restarts crashed workers). Limitation verified on OpenSwoole 26.2: on SIGTERM the
 *   worker's event loop exits immediately, so requests suspended on I/O or timers are dropped;
 *   reload (SIGUSR1) still drains. Use it only when shutdown drops are acceptable.
 */
enum ServerMode: string
{
    case Simple = 'simple';
    case Process = 'process';

    public function openSwooleMode(): int
    {
        return match ($this) {
            self::Simple => Server::SIMPLE_MODE,
            self::Process => Server::POOL_MODE,
        };
    }
}
