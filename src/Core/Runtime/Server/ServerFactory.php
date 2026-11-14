<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Server;

use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Runtime\Config\RuntimeConfig;
use Omnitrix\Core\Runtime\Exception\ErrorHandler;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Omnitrix\Core\Runtime\Shutdown\ResourceRegistry;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use Omnitrix\Core\Runtime\Worker\WorkerLifecycle;
use Omnitrix\Core\Runtime\Worker\WorkerRole;
use OpenSwoole\Http\Server;
use OpenSwoole\Runtime;
use OpenSwoole\Server\Task;
use OpenSwoole\Timer;
use Psr\Log\LoggerInterface;
use Throwable;

use function getmypid;

/**
 * Builds the OpenSwoole HTTP server and wires every lifecycle event to the framework.
 *
 * Worker start: enable runtime hooks, install the error handler, build the worker container
 * and context, run start listeners. Worker exit (graceful drain) only flags the worker as
 * draining; worker stop runs stop listeners, releases resources exactly once and clears timers
 * so the process can exit. All callbacks are exception-safe.
 *
 * @internal
 */
final class ServerFactory
{
    /**
     * @param callable(WorkerContext): void $configureContainer populates the per-worker container
     * @param null|callable(Server, Task): mixed $taskHandler required when task workers > 0
     */
    public function __construct(
        private readonly RuntimeConfig $config,
        private readonly WorkerLifecycle $lifecycle,
        private readonly ExceptionHandlerInterface $exceptionHandler,
        private readonly LoggerInterface $logger,
        private $configureContainer,
        private $taskHandler = null,
    ) {}

    public function create(RequestEntrypoint $entrypoint): Server
    {
        $server = new Server($this->config->host, $this->config->port, $this->config->mode->openSwooleMode());
        $server->set($this->config->toOpenSwooleSettings());

        /** @var WorkerContext|null $worker per-process state (each worker is a separate process) */
        $worker = null;
        $stopped = false;

        $server->on('WorkerStart', function (Server $server, int $workerId) use (&$worker, &$stopped): void {
            $stopped = false;
            try {
                Runtime::enableCoroutine(true, $this->config->hookFlags);
                (new ErrorHandler())->register();

                $container = new Container();
                $resources = new ResourceRegistry();
                $worker = new WorkerContext(
                    workerId: $workerId,
                    pid: (int) getmypid(),
                    role: $server->taskworker === true ? WorkerRole::Task : WorkerRole::Http,
                    container: $container,
                    resources: $resources,
                    server: $server,
                );
                $container->instance(WorkerContext::class, $worker);
                $container->instance(ResourceRegistry::class, $resources);
                $container->instance(RuntimeConfig::class, $this->config);
                $container->instance(LoggerInterface::class, $this->logger);
                $container->instance(ExceptionHandlerInterface::class, $this->exceptionHandler);
                ($this->configureContainer)($worker);

                $failures = $this->lifecycle->start($worker);
                $this->logger->info('worker started', ['worker_id' => $workerId, 'pid' => $worker->pid, 'role' => $worker->role->value, 'start_failures' => $failures]);
            } catch (Throwable $e) {
                $this->exceptionHandler->handle($e, ['origin' => 'worker.start', 'worker_id' => $workerId]);
            }
        });

        $draining = false;

        // WorkerExit fires under reload_async / graceful stop while in-flight requests are still
        // draining: nothing may be torn down yet (timers, pools and contexts are in use). Only
        // mark the state so new background work can be refused. WorkerStop is the real end.
        $server->on('WorkerExit', function (Server $server, int $workerId) use (&$draining, &$worker): void {
            if (!$draining) {
                $draining = true;
                $this->logger->info('worker draining', ['worker_id' => $workerId, 'pid' => $worker?->pid]);
            }
        });

        $server->on('WorkerStop', function (Server $server, int $workerId) use (&$worker, &$stopped): void {
            if ($stopped || $worker === null) {
                return;
            }
            $stopped = true;
            try {
                $failures = $this->lifecycle->stop($worker);
                $this->logger->info('worker stopped', ['worker_id' => $workerId, 'pid' => $worker->pid, 'stop_failures' => $failures]);
            } catch (Throwable $e) {
                $this->exceptionHandler->handle($e, ['origin' => 'worker.stop', 'worker_id' => $workerId]);
            } finally {
                Timer::clearAll();
            }
        });

        $server->on('WorkerError', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void {
            $this->logger->error('worker exited abnormally', ['worker_id' => $workerId, 'pid' => $workerPid, 'exit_code' => $exitCode, 'signal' => $signal]);
        });

        $server->on('Request', $entrypoint);

        if ($this->config->taskWorkers > 0) {
            $handler = $this->taskHandler;
            $server->on('Task', function (Server $server, Task $task) use ($handler): void {
                try {
                    $result = $handler === null ? null : $handler($server, $task);
                    $task->finish($result);
                } catch (Throwable $e) {
                    $this->exceptionHandler->handle($e, ['origin' => 'task', 'task_id' => $task->id]);
                    $task->finish(null);
                }
            });
            $server->on('Finish', static function (): void {});
        }

        $server->on('Shutdown', function (): void {
            $this->logger->info('server shutdown complete');
        });

        return $server;
    }
}
