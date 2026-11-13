<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Worker;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Runtime\Shutdown\ResourceRegistry;
use OpenSwoole\Server;

/**
 * Everything a worker-scoped service may need at start-up: identity, the worker container,
 * the resource registry (release on stop) and the raw server for stats/task dispatch.
 */
final readonly class WorkerContext
{
    public function __construct(
        public int $workerId,
        public int $pid,
        public WorkerRole $role,
        public Container $container,
        public ResourceRegistry $resources,
        public ?Server $server,
    ) {}
}
