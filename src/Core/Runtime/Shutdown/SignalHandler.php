<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Shutdown;

use OpenSwoole\Process;

use function array_keys;

use const SIGINT;
use const SIGTERM;

/**
 * Signal handling for standalone coroutine runtimes (queue consumers, CPU pools, tooling).
 *
 * Inside an OpenSwoole server the master/manager already own SIGTERM/SIGINT (graceful stop)
 * and SIGUSR1 (reload); do not install this there. Handlers run on the event loop, so they may
 * use coroutine APIs. {@see release()} removes the handlers so the reactor can exit.
 */
final class SignalHandler
{
    /** @var array<int, true> */
    private array $installed = [];

    /**
     * @param list<int> $signals
     * @param callable(int): void $onSignal
     */
    public function onTerminate(callable $onSignal, array $signals = [SIGTERM, SIGINT]): void
    {
        foreach ($signals as $signal) {
            Process::signal($signal, static function (int $received) use ($onSignal): void {
                $onSignal($received);
            });
            $this->installed[$signal] = true;
        }
    }

    public function release(): void
    {
        foreach (array_keys($this->installed) as $signal) {
            Process::signal($signal, null);
        }
        $this->installed = [];
    }

    /** @return list<int> */
    public function installedSignals(): array
    {
        return array_keys($this->installed);
    }
}
