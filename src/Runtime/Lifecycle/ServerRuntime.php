<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Lifecycle;

use LogicException;
use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Config\RuntimeConfig;
use Omnitrix\Core\Runtime\Context\RequestContext;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Omnitrix\Core\Runtime\Exception\LoggingExceptionHandler;
use Omnitrix\Core\Runtime\Server\RequestEntrypoint;
use Omnitrix\Core\Runtime\Server\ServerFactory;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use Omnitrix\Core\Runtime\Worker\WorkerLifecycle;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Server\Task;
use Psr\Log\LoggerInterface;

use function is_array;

/**
 * Application lifecycle root: Created → Built → Running → Stopped.
 *
 * Owns the validated configuration, the master-process logger and exception handler, the
 * worker lifecycle listeners, and (once built) the OpenSwoole server. Higher layers (HTTP
 * kernel, routing) plug in through {@see handle()} and {@see onWorkerStart()}; this class
 * stays free of HTTP semantics.
 */
final class ServerRuntime
{
    private LifecycleState $state = LifecycleState::Created;

    private readonly WorkerLifecycle $workerLifecycle;

    private ?Server $server = null;

    /** @var null|callable(Request, Response, RequestContext): void */
    private $requestHandler = null;

    /** @var list<callable(WorkerContext): void> */
    private array $containerConfigurators = [];

    /** @var null|callable(Server, Task): mixed */
    private $taskHandler = null;

    /** @var list<callable(Server): void> */
    private array $buildHooks = [];

    /**
     * @param array<string, mixed> $config full application configuration (all sections)
     */
    private function __construct(
        private readonly array $config,
        private readonly RuntimeConfig $runtimeConfig,
        private LoggerInterface $logger,
        private ExceptionHandlerInterface $exceptionHandler,
    ) {
        $this->workerLifecycle = new WorkerLifecycle($this->exceptionHandler);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfigurationException
     */
    public static function create(array $config, ?LoggerInterface $logger = null, ?ExceptionHandlerInterface $exceptionHandler = null): self
    {
        $server = $config['server'] ?? [];
        if (!is_array($server)) {
            throw InvalidConfigurationException::at('server', 'expected an array');
        }
        /** @var array<string, mixed> $server */
        $runtime = RuntimeConfig::fromArray($server);
        $logger ??= new JsonLogger();

        return new self($config, $runtime, $logger, $exceptionHandler ?? new LoggingExceptionHandler($logger));
    }

    public function state(): LifecycleState
    {
        return $this->state;
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    public function runtimeConfig(): RuntimeConfig
    {
        return $this->runtimeConfig;
    }

    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    public function exceptionHandler(): ExceptionHandlerInterface
    {
        return $this->exceptionHandler;
    }

    public function workerLifecycle(): WorkerLifecycle
    {
        return $this->workerLifecycle;
    }

    /** @param callable(WorkerContext): void $listener */
    public function onWorkerStart(callable $listener): self
    {
        $this->assertState(LifecycleState::Created, 'onWorkerStart');
        $this->workerLifecycle->onStart($listener);

        return $this;
    }

    /** @param callable(WorkerContext): void $listener */
    public function onWorkerStop(callable $listener): self
    {
        $this->assertState(LifecycleState::Created, 'onWorkerStop');
        $this->workerLifecycle->onStop($listener);

        return $this;
    }

    /**
     * Registers services in each worker's container before start listeners run.
     *
     * @param callable(WorkerContext): void $configurator
     */
    public function configureContainer(callable $configurator): self
    {
        $this->assertState(LifecycleState::Created, 'configureContainer');
        $this->containerConfigurators[] = $configurator;

        return $this;
    }

    /** @param callable(Request, Response, RequestContext): void $handler */
    public function handle(callable $handler): self
    {
        $this->assertState(LifecycleState::Created, 'handle');
        $this->requestHandler = $handler;

        return $this;
    }

    /** @param callable(Server, Task): mixed $handler */
    public function onTask(callable $handler): self
    {
        $this->assertState(LifecycleState::Created, 'onTask');
        $this->taskHandler = $handler;

        return $this;
    }

    /**
     * Runs after the server object is created and before it starts (master process); used to
     * attach extra processes or listeners.
     *
     * @param callable(Server): void $hook
     */
    public function onBuild(callable $hook): self
    {
        $this->assertState(LifecycleState::Created, 'onBuild');
        $this->buildHooks[] = $hook;

        return $this;
    }

    /** Builds the server without starting it (useful for inspection and tests). */
    public function build(): Server
    {
        $this->assertState(LifecycleState::Created, 'build');
        $handler = $this->requestHandler ?? throw new LogicException('No request handler registered; call ServerRuntime::handle() first');

        $configurators = $this->containerConfigurators;
        $factory = new ServerFactory(
            $this->runtimeConfig,
            $this->workerLifecycle,
            $this->exceptionHandler,
            $this->logger,
            static function (WorkerContext $worker) use ($configurators): void {
                foreach ($configurators as $configure) {
                    $configure($worker);
                }
            },
            $this->taskHandler,
        );

        $this->server = $factory->create(new RequestEntrypoint($handler, $this->exceptionHandler));
        foreach ($this->buildHooks as $hook) {
            $hook($this->server);
        }
        $this->state = LifecycleState::Built;

        return $this->server;
    }

    /** Builds (if needed) and starts the server. Blocks until the server shuts down. */
    public function run(): void
    {
        if ($this->state === LifecycleState::Created) {
            $this->build();
        }
        $this->assertState(LifecycleState::Built, 'run');
        $server = $this->server ?? throw new LogicException('Server not built');

        $this->state = LifecycleState::Running;
        $this->logger->info('server starting', ['host' => $this->runtimeConfig->host, 'port' => $this->runtimeConfig->port, 'mode' => $this->runtimeConfig->mode->value, 'workers' => $this->runtimeConfig->workers]);
        try {
            $server->start();
        } finally {
            $this->state = LifecycleState::Stopped;
        }
    }

    public function server(): ?Server
    {
        return $this->server;
    }

    /** Requests a graceful shutdown (from any worker or the master). */
    public function stop(): void
    {
        $this->server?->shutdown();
    }

    /** Gracefully restarts all workers. */
    public function reload(): void
    {
        $this->server?->reload();
    }

    private function assertState(LifecycleState $expected, string $operation): void
    {
        if ($this->state !== $expected) {
            throw new LogicException("ServerRuntime::{$operation}() requires state '{$expected->value}', current state is '{$this->state->value}'");
        }
    }
}
