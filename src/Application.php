<?php

declare(strict_types=1);

namespace Omnitrix;

use InvalidArgumentException;
use LogicException;
use Omnitrix\Auth\AuthManager;
use Omnitrix\Contracts\Encrypter;
use Omnitrix\Contracts\EventDispatcher;
use Omnitrix\Contracts\Hasher;
use Omnitrix\Contracts\SessionHandler;
use Omnitrix\Core\Cache\Cache as CacheService;
use Omnitrix\Core\Container\Container;
use Omnitrix\Core\Database\DatabaseManager;
use Omnitrix\Core\Http\Server\HttpServerAdapter;
use Omnitrix\Core\Http\Server\HttpServerConfig;
use Omnitrix\Core\HttpClient\HttpClient;
use Omnitrix\Core\HttpClient\HttpClientConfig;
use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Observability\MetricsRegistry;
use Omnitrix\Core\Process\CpuLoadBoard;
use Omnitrix\Core\Process\CpuPool;
use Omnitrix\Core\Process\CpuPoolConfig;
use Omnitrix\Core\Process\CpuWorker;
use Omnitrix\Core\Queue\QueueProducer;
use Omnitrix\Core\Queue\RedisQueue;
use Omnitrix\Core\Redis\RedisClient;
use Omnitrix\Core\Redis\RedisConfig;
use Omnitrix\Core\Runtime\Config\InvalidConfigurationException;
use Omnitrix\Core\Runtime\Coroutine\Timers;
use Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface;
use Omnitrix\Core\Runtime\Lifecycle\ServerRuntime;
use Omnitrix\Core\Runtime\Shutdown\ResourceRegistry;
use Omnitrix\Core\Runtime\Worker\WorkerContext;
use Omnitrix\Encryption\AesEncrypter;
use Omnitrix\Events\Dispatcher;
use Omnitrix\Facades\Facade;
use Omnitrix\Hashing\HashManager;
use Omnitrix\Http\ErrorResponseFactory;
use Omnitrix\Http\HttpKernel;
use Omnitrix\Http\Middleware\MiddlewareInterface;
use Omnitrix\Mail\Mailer;
use Omnitrix\Mail\MailerFactory;
use Omnitrix\Observability\HealthCheck;
use Omnitrix\RateLimit\Limit;
use Omnitrix\RateLimit\RateLimiter;
use Omnitrix\RateLimit\RedisRateLimiter;
use Omnitrix\RateLimit\TableRateLimiter;
use Omnitrix\RateLimit\Throttle;
use Omnitrix\Routing\HandlerResolver;
use Omnitrix\Routing\Route;
use Omnitrix\Routing\Router;
use Omnitrix\Routing\UrlGenerator;
use Omnitrix\Session\ArraySessionHandler;
use Omnitrix\Session\RedisSessionHandler;
use Omnitrix\Session\SessionConfig;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Server;
use OpenSwoole\Process;
use OpenSwoole\Runtime;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

use function getmypid;
use function hrtime;
use function is_array;
use function is_bool;
use function is_callable;
use function is_file;
use function is_float;
use function is_int;
use function is_string;

use const SIGTERM;

/**
 * The developer-facing application: configuration, routes, middleware, run.
 *
 * ```php
 * $app = Application::create(['server' => ['port' => 8080]]);
 * $app->get('/users/{id:\d+}', fn (Request $r) => Response::json(['id' => $r->routeInt('id')]));
 * $app->run();
 * ```
 *
 * Routes and middleware are declared in the master process; each worker builds its own
 * {@see HttpKernel} (and compiles the router) in `WorkerStart`, so handlers and services are
 * worker-scoped and never shared across processes.
 */
final class Application
{
    private readonly Router $router;

    private readonly HttpServerConfig $http;

    private readonly bool $debug;

    private ?CpuPoolConfig $cpuConfig = null;

    private ?CpuLoadBoard $cpuBoard = null;

    private ?MetricsRegistry $metrics = null;

    private ?Container $workerContainer = null;

    private ?TableRateLimiter $rateLimitTable = null;

    /** @var array<string, callable(Http\Request): (Limit|list<Limit>|null)> */
    private array $rateLimiters = [];

    /** @var list<array{string|list<string>, callable|string|array{class-string, string}, int}> listeners registered with listen() */
    private array $eventListeners = [];

    /** Worker timer publishing this worker's metric series. */
    private ?int $metricsTimer = null;

    /** @var list<MiddlewareInterface|callable(Http\Request, Http\Middleware\Next): Http\Response> */
    private array $middleware = [];

    private function __construct(private readonly ServerRuntime $runtime)
    {
        $this->router = new Router();
        $config = $runtime->config();
        $http = $config['http'] ?? [];
        if (!is_array($http)) {
            throw InvalidConfigurationException::at('http', 'expected an array');
        }
        /** @var array<string, mixed> $http */
        $this->http = HttpServerConfig::fromArray($http, $runtime->runtimeConfig()->packageMaxLength);
        $app = $config['app'] ?? [];
        $debug = $config['debug'] ?? (is_array($app) ? ($app['debug'] ?? false) : false);
        if (!is_bool($debug)) {
            throw InvalidConfigurationException::at('debug', 'expected boolean');
        }
        $this->debug = $debug;
        $database = $config['database'] ?? null;
        if (is_array($database) && $database !== []) {
            /** @var array<string, mixed> $database */
            DatabaseManager::validate($database, isset($config['redis']));
        }
        $rateLimit = $config['rate_limit'] ?? [];
        if (!is_array($rateLimit)) {
            throw InvalidConfigurationException::at('rate_limit', 'expected an array');
        }
        $driver = $rateLimit['driver'] ?? (isset($config['redis']) ? 'redis' : 'memory');
        if ($driver === 'memory') {
            $keys = $rateLimit['max_keys'] ?? 16384;
            if (!is_int($keys) || $keys < 16) {
                throw InvalidConfigurationException::at('rate_limit.max_keys', 'expected an integer of at least 16');
            }
            // Shared memory: created in the master so every worker counts in the same table.
            $this->rateLimitTable = new TableRateLimiter($keys);
        } elseif ($driver !== 'redis') {
            throw InvalidConfigurationException::at('rate_limit.driver', "expected 'memory' or 'redis'");
        } elseif (!isset($config['redis'])) {
            throw InvalidConfigurationException::at('rate_limit.driver', "'redis' needs the redis configuration section");
        }
    }

    /**
     * Defines a named rate limiter for {@see Throttle::using()} (call it in `bootstrap.php`):
     * `$app->rateLimiter('api', fn (Request $r) => Limit::perMinute(60)->by(...))`.
     *
     * @param callable(Http\Request): (Limit|list<Limit>|null) $callback
     */
    public function rateLimiter(string $name, callable $callback): self
    {
        $this->rateLimiters[$name] = $callback;

        return $this;
    }

    /**
     * @param array<string, mixed> $config sections: server, http, debug (and module sections later)
     *
     * @throws InvalidConfigurationException
     */
    public static function create(array $config, ?LoggerInterface $logger = null, ?ExceptionHandlerInterface $exceptionHandler = null): self
    {
        return new self(ServerRuntime::create($config, $logger ?? self::defaultLogger($config), $exceptionHandler));
    }

    /**
     * JSON lines on stderr, filtered by `app.log_level` (default `info`).
     *
     * @param array<string, mixed> $config
     */
    private static function defaultLogger(array $config): LoggerInterface
    {
        $app = $config['app'] ?? [];
        $level = is_array($app) ? ($app['log_level'] ?? LogLevel::INFO) : LogLevel::INFO;
        if (!is_string($level)) {
            throw InvalidConfigurationException::at('app.log_level', 'expected a PSR-3 level name');
        }
        try {
            return new JsonLogger(minimumLevel: $level);
        } catch (InvalidArgumentException) {
            throw InvalidConfigurationException::at('app.log_level', "unknown level '{$level}'");
        }
    }

    // --- routing ----------------------------------------------------------------------------

    public function get(string $pattern, mixed $handler): Route
    {
        return $this->router->get($pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): Route
    {
        return $this->router->post($pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): Route
    {
        return $this->router->put($pattern, $handler);
    }

    public function patch(string $pattern, mixed $handler): Route
    {
        return $this->router->patch($pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->router->delete($pattern, $handler);
    }

    public function options(string $pattern, mixed $handler): Route
    {
        return $this->router->options($pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): Route
    {
        return $this->router->any($pattern, $handler);
    }

    /** @param list<string> $methods */
    public function map(array $methods, string $pattern, mixed $handler): Route
    {
        return $this->router->map($methods, $pattern, $handler);
    }

    /**
     * @param callable(Router): void $define
     * @param list<MiddlewareInterface|callable(Http\Request, Http\Middleware\Next): Http\Response> $middleware
     */
    public function group(string $prefix, callable $define, array $middleware = []): self
    {
        $this->router->group($prefix, $define, $middleware);

        return $this;
    }

    /**
     * Loads a route file. The file receives `$app` (this application) and `$router` in scope,
     * or may return a `callable(Router): void`.
     */
    public function routes(string $file): self
    {
        if (!is_file($file)) {
            throw new InvalidConfigurationException("Route file not found: {$file}");
        }
        $app = $this;
        $router = $this->router;
        $result = (static function () use ($app, $router, $file): mixed {
            return require $file;
        })();
        if (is_callable($result)) {
            $result($router);
        }

        return $this;
    }

    /** Global middleware applied to every route, in order. */
    public function middleware(MiddlewareInterface|callable ...$middleware): self
    {
        foreach ($middleware as $layer) {
            $this->middleware[] = $layer;
        }

        return $this;
    }

    public function router(): Router
    {
        return $this->router;
    }

    // --- lifecycle --------------------------------------------------------------------------

    /**
     * The underlying server runtime.
     *
     * @internal Engine access for framework components and tests; applications use the methods
     *           of this class instead.
     */
    public function runtime(): ServerRuntime
    {
        return $this->runtime;
    }

    /** The application logger (PSR-3). Structured JSON on stderr unless one was passed to create(). */
    public function logger(): LoggerInterface
    {
        return $this->runtime->logger();
    }

    /** Receives every exception caught at a coroutine or request boundary. */
    public function exceptionHandler(): ExceptionHandlerInterface
    {
        return $this->runtime->exceptionHandler();
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->runtime->config();
    }

    /** @internal */
    public function httpConfig(): HttpServerConfig
    {
        return $this->http;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }

    /** @param callable(WorkerContext): void $listener */
    /**
     * Registers an event listener in every worker (call it in `bootstrap.php`; listeners can
     * also be listed under `events.listen` in configuration).
     *
     * @param string|list<string>                              $events
     * @param callable|string|array{class-string, string}      $listener
     */
    public function listen(string|array $events, callable|string|array $listener, int $priority = 0): self
    {
        $this->eventListeners[] = [$events, $listener, $priority];

        return $this;
    }

    public function onWorkerStart(callable $listener): self
    {
        $this->runtime->onWorkerStart($listener);

        return $this;
    }

    /** @param callable(WorkerContext): void $listener */
    public function onWorkerStop(callable $listener): self
    {
        $this->runtime->onWorkerStop($listener);

        return $this;
    }

    /** @param callable(WorkerContext): void $configurator */
    public function configureContainer(callable $configurator): self
    {
        $this->runtime->configureContainer($configurator);

        return $this;
    }

    /**
     * Builds the request kernel for one worker.
     *
     * @internal Used by the server bootstrap and by tests that drive the kernel without a server.
     */
    public function makeKernel(?Container $container = null): HttpKernel
    {
        $this->router->compile();
        $kernel = new HttpKernel($this->router, new HandlerResolver($container), new ErrorResponseFactory($this->debug), $this->runtime->exceptionHandler());
        $kernel->middleware(...$this->middleware);

        return $kernel;
    }

    /** Builds the server without starting it. */
    public function build(): Server
    {
        $this->installHandler();

        return $this->runtime->build();
    }

    /** Starts the server (blocking). */
    public function run(): void
    {
        $this->installHandler();
        $this->runtime->run();
    }

    public function stop(): void
    {
        $this->runtime->stop();
    }

    /**
     * Records request counts and latency histograms (shared across workers) and serves them in
     * Prometheus text format at `$path`, together with server gauges (event-loop lag,
     * coroutines, connections, memory) and every worker's pool and cache statistics. Protect the path with
     * `$middleware` (e.g. a token check) or keep it on an internal network in production.
     *
     * @param list<MiddlewareInterface|callable(Http\Request, Http\Middleware\Next): Http\Response> $middleware
     */
    public function enableMetrics(string $path = '/metrics', array $middleware = []): self
    {
        if ($this->metrics !== null) {
            throw new LogicException('Metrics are already enabled');
        }
        $this->metrics = new MetricsRegistry(); // shared memory, created in the master
        $registry = $this->metrics;
        $route = $this->router->get($path, function () use ($registry): Http\Response {
            $registry->publish($this->workerGauges()); // this worker's numbers are current

            return (new Http\Response($registry->render($this->gauges())))->header('Content-Type', 'text/plain; version=0.0.4');
        })->name('omnitrix.metrics');
        if ($middleware !== []) {
            $route->middleware(...$middleware);
        }

        return $this;
    }

    /**
     * Registers `{$prefix}/live` (process is up) and `{$prefix}/ready` (dependencies answer).
     * Database and Redis checks are added automatically when those modules are configured.
     *
     * @param array<string, callable(): bool> $checks additional named checks
     */
    public function health(string $prefix = '/health', array $checks = [], float $timeout = 2.0): self
    {
        $health = new HealthCheck($timeout);
        foreach ($checks as $name => $check) {
            $health->add($name, $check);
        }
        $config = $this->runtime->config();
        if (isset($config['database'])) {
            $health->add('database', function (): bool {
                $manager = $this->workerContainer?->get(DatabaseManager::class);

                return $manager instanceof DatabaseManager && $manager->connection()->selectOne('select 1 as ok') !== null;
            });
        }
        if (isset($config['redis'])) {
            $health->add('redis', function (): bool {
                $client = $this->workerContainer?->get(RedisClient::class);

                return $client instanceof RedisClient && $client->ping();
            });
        }
        $this->router->get($prefix . '/live', static fn(): Http\Response => $health->live())->name('omnitrix.health.live');
        $this->router->get($prefix . '/ready', static fn(): Http\Response => $health->ready())->name('omnitrix.health.ready');

        return $this;
    }

    /**
     * Server-wide gauges (any worker reports the same values).
     *
     * @return array<string, int|float>
     */
    private function gauges(): array
    {
        $gauges = [];
        $stats = $this->runtime->server()?->stats();
        if (is_array($stats)) {
            foreach (['connections_active' => 'omnitrix_connections_active', 'connections_accepted' => 'omnitrix_connections_accepted_total', 'requests_total' => 'omnitrix_server_requests_total', 'workers_idle' => 'omnitrix_workers_idle'] as $key => $name) {
                $value = $stats[$key] ?? null;
                if (is_int($value) || is_float($value)) {
                    $gauges[$name] = $value;
                }
            }
        }

        return $gauges;
    }

    /**
     * This worker's series, labelled with its id: published to shared memory so that every
     * worker's numbers appear whichever worker serves `/metrics`.
     *
     * @return array<string, int|float>
     */
    private function workerGauges(): array
    {
        $gauges = [];
        $workerId = $this->runtime->server()?->getWorkerId();
        $worker = is_int($workerId) ? (string) $workerId : '0';
        $stats = $this->runtime->server()?->stats();
        if (is_array($stats)) {
            foreach (['coroutine_num' => 'omnitrix_worker_coroutines', 'event_loop_lag_ms' => 'omnitrix_event_loop_lag_ms', 'event_loop_lag_max_ms' => 'omnitrix_event_loop_lag_max_ms', 'event_loop_lag_avg_ms' => 'omnitrix_event_loop_lag_avg_ms', 'worker_memory_usage' => 'omnitrix_worker_memory_bytes'] as $key => $name) {
                $value = $stats[$key] ?? null;
                if (is_int($value) || is_float($value)) {
                    $gauges["{$name}{worker=\"{$worker}\"}"] = $value;
                }
            }
        }
        $container = $this->workerContainer;
        if ($container !== null && $container->has(DatabaseManager::class)) {
            $manager = $container->get(DatabaseManager::class);
            if ($manager instanceof DatabaseManager) {
                foreach ($manager->names() as $name) {
                    foreach ($manager->connection($name)->stats()->toArray() as $k => $v) {
                        $gauges["omnitrix_db_pool_{$k}{connection=\"{$name}\",worker=\"{$worker}\"}"] = $v;
                    }
                    if ($manager->connection($name)->cacheEnabled()) {
                        foreach ($manager->connection($name)->cacheStats() as $k => $v) {
                            $gauges["omnitrix_db_cache_{$k}_total{connection=\"{$name}\",worker=\"{$worker}\"}"] = $v;
                        }
                    }
                }
            }
        }
        if ($container !== null && $container->has(RedisClient::class)) {
            $client = $container->get(RedisClient::class);
            if ($client instanceof RedisClient) {
                foreach ($client->stats() as $k => $v) {
                    $gauges["omnitrix_redis_pool_{$k}{worker=\"{$worker}\"}"] = $v;
                }
            }
        }
        if ($container !== null && $container->has(HttpClient::class)) {
            $client = $container->get(HttpClient::class);
            if ($client instanceof HttpClient) {
                foreach ($client->stats() as $k => $v) {
                    if (is_int($v)) {
                        $gauges["omnitrix_http_client_{$k}{worker=\"{$worker}\"}"] = $v;
                    }
                }
            }
        }

        return $gauges;
    }

    /**
     * Enables the CPU worker pool: `$workers` dedicated processes, supervised and restarted by
     * the server manager, execute the registered jobs. Dispatch work from request handlers with
     * `Cpu::run('name', $args)`.
     *
     * @param array<string, callable(array<mixed>): mixed> $jobs       job name => handler; arguments and results must be JSON-compatible
     * @param float                                         $timeout    default per-job timeout in seconds (queueing included)
     * @param int                                           $maxPending jobs one HTTP worker may have in flight before callers time out
     */
    public function cpu(array $jobs, int $workers = 2, float $timeout = 30.0, int $maxPending = 64): self
    {
        if ($this->cpuConfig !== null) {
            throw new LogicException('The CPU pool is already configured');
        }
        $config = new CpuPoolConfig($workers, $timeout, $maxPending, '', 'p' . $this->runtime->runtimeConfig()->port);
        $this->cpuConfig = $config;
        $this->cpuBoard = new CpuLoadBoard($config->workers); // shared memory, created before fork
        $logger = $this->runtime->logger();
        $this->runtime->onBuild(static function (Server $server) use ($config, $jobs, $logger): void {
            for ($i = 0; $i < $config->workers; $i++) {
                $index = $i;
                $server->addProcess(new Process(static function () use ($config, $jobs, $index, $logger): void {
                    $worker = new CpuWorker($config, $index, $jobs);
                    Process::signal(SIGTERM, static function () use ($worker): void {
                        $worker->stop();
                    });
                    $logger->info('cpu worker started', ['index' => $index, 'pid' => getmypid()]);
                    $worker->run();
                }, false, 0, true));
            }
        });

        return $this;
    }

    /**
     * Runs `$body` with the application's services (database, Redis, cache, queue producer,
     * HTTP client) available through the facades, outside the HTTP server: console commands,
     * queue workers, scripts and tests. Starts a coroutine container (with runtime hooks)
     * when called outside a coroutine; services are released when `$body` returns or throws.
     *
     * @template T
     *
     * @param callable(): T $body
     *
     * @return T
     */
    public function withServices(callable $body): mixed
    {
        $run = function () use ($body): mixed {
            $container = new Container();
            $resources = new ResourceRegistry();
            $previous = Facade::container();
            $this->registerServices($container, $resources, warmUp: false);
            Facade::setContainer($container);
            try {
                return $body();
            } finally {
                Facade::setContainer($previous);
                $resources->releaseAll();
            }
        };
        if (Coroutine::getCid() > 0) {
            return $run();
        }
        $result = null;
        $failure = null;
        $runtime = $this->runtime->runtimeConfig();
        Coroutine::set(['log_level' => $runtime->logLevel] + ($runtime->logFile === null ? [] : ['log_file' => $runtime->logFile]));
        Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
        Coroutine::run(static function () use ($run, &$result, &$failure): void {
            try {
                $result = $run();
            } catch (Throwable $e) {
                $failure = $e;
            }
        });
        if ($failure !== null) {
            throw $failure;
        }

        /** @var T $result */
        return $result;
    }

    /**
     * Registers the optional modules whose config sections are present. Each module is a
     * worker-scoped singleton with its resources registered for release on WorkerStop.
     */
    private function registerModules(WorkerContext $worker): void
    {
        if ($this->cpuConfig !== null) {
            $worker->container->instance(CpuPool::class, new CpuPool($this->cpuConfig, $this->cpuBoard ?? new CpuLoadBoard($this->cpuConfig->workers)));
        }
        $this->registerServices($worker->container, $worker->resources, warmUp: true);
    }

    private function registerServices(Container $container, ResourceRegistry $resources, bool $warmUp): void
    {
        $config = $this->runtime->config();
        $httpClientSection = $config['http_client'] ?? [];
        if (!is_array($httpClientSection)) {
            throw InvalidConfigurationException::at('http_client', 'expected an array');
        }
        /** @var array<string, mixed> $httpClientSection */
        $httpClient = new HttpClient(HttpClientConfig::fromArray($httpClientSection));
        $container->instance(HttpClient::class, $httpClient);
        $resources->register('http_client', static function () use ($httpClient): void {
            $httpClient->close();
        }, 100);

        $hashing = $config['hashing'] ?? [];
        if (!is_array($hashing)) {
            throw InvalidConfigurationException::at('hashing', 'expected an array');
        }
        /** @var array<string, mixed> $hashing */
        $container->instance(Hasher::class, HashManager::fromConfig($hashing));
        $app = $config['app'] ?? [];
        /** @var array<string, mixed> $app */
        $encrypter = is_array($app) ? AesEncrypter::fromConfig($app) : null;
        if ($encrypter !== null) {
            $container->instance(Encrypter::class, $encrypter);
        }
        $baseUrl = is_string($app['url'] ?? null) ? $app['url'] : '';
        $appKey = is_string($app['key'] ?? null) ? $app['key'] : null;
        $container->instance(UrlGenerator::class, new UrlGenerator($this->router, $baseUrl, $appKey));

        $redisClient = null;
        $redis = $config['redis'] ?? null;
        if (is_array($redis)) {
            /** @var array<string, mixed> $redis */
            $client = new RedisClient(RedisConfig::fromArray($redis));
            $redisClient = $client;
            $container->instance(RedisClient::class, $client);
            $resources->register('redis', static function () use ($client): void {
                $client->close();
            }, 60);
            $cacheSection = $config['cache'] ?? [];
            if (!is_array($cacheSection)) {
                throw InvalidConfigurationException::at('cache', 'expected an array');
            }
            $prefix = $cacheSection['prefix'] ?? 'cache:';
            $ttl = $cacheSection['ttl'] ?? 3600;
            if (!is_string($prefix) || !is_int($ttl) || $ttl < 0) {
                throw InvalidConfigurationException::at('cache', "expected 'prefix' string and 'ttl' non-negative integer");
            }
            $container->instance(CacheService::class, new CacheService($client, $prefix, $ttl));
            $container->instance(QueueProducer::class, new QueueProducer(new RedisQueue($client)));
        }

        $database = $config['database'] ?? null;
        if (is_array($database) && $database !== []) {
            /** @var array<string, mixed> $database */
            $manager = new DatabaseManager($database, $this->runtime->logger(), $redisClient);
            $container->instance(DatabaseManager::class, $manager);
            $resources->register('database', static function () use ($manager): void {
                $manager->close();
            }, 50);
            if ($warmUp) {
                $manager->warmUp();
            }
        }

        $container->instance(EventDispatcher::class, $this->makeDispatcher($container, $config));

        $auth = $config['auth'] ?? null;
        if (is_array($auth)) {
            /** @var array<string, mixed> $auth */
            $hasher = $container->get(Hasher::class);
            if ($hasher instanceof Hasher) {
                $container->instance(AuthManager::class, new AuthManager($auth, $hasher));
            }
        }

        $mail = $config['mail'] ?? null;
        if (is_array($mail)) {
            /** @var array<string, mixed> $mail */
            $producer = $container->has(QueueProducer::class) ? $container->get(QueueProducer::class) : null;
            $container->instance(Mailer::class, MailerFactory::make($mail, $this->runtime->logger(), $producer instanceof QueueProducer ? $producer->dispatch(...) : null));
        }

        $session = $config['session'] ?? null;
        if (is_array($session)) {
            /** @var array<string, mixed> $session */
            $sessionConfig = SessionConfig::fromArray($session, $redisClient !== null);
            $container->instance(SessionConfig::class, $sessionConfig);
            $handler = match ($sessionConfig->driver) {
                'redis' => $redisClient !== null ? new RedisSessionHandler($redisClient, $sessionConfig->prefix) : null,
                'array' => new ArraySessionHandler(),
                default => null,
            };
            if ($handler !== null) {
                $container->instance(SessionHandler::class, $handler);
            }
        }

        $backend = $this->rateLimitTable ?? ($redisClient !== null ? new RedisRateLimiter($redisClient) : null);
        if ($backend !== null) {
            $limiter = new RateLimiter($backend);
            foreach ($this->rateLimiters as $name => $callback) {
                $limiter->for($name, $callback);
            }
            $container->instance(RateLimiter::class, $limiter);
        }
    }

    /** @param array<string, mixed> $config */
    private function makeDispatcher(Container $container, array $config): Dispatcher
    {
        $producer = $container->has(QueueProducer::class) ? $container->get(QueueProducer::class) : null;
        $queue = $producer instanceof QueueProducer ? $producer->dispatch(...) : null;
        $manager = $container->has(DatabaseManager::class) ? $container->get(DatabaseManager::class) : null;
        $deferred = $manager instanceof DatabaseManager
            ? static function (callable $callback) use ($manager): bool {
                $db = $manager->connection();
                if (!$db->inTransaction()) {
                    return false;
                }
                $db->afterCommit($callback);

                return true;
            }
        : null;
        $dispatcher = new Dispatcher($container, $queue, $deferred);
        $events = $config['events'] ?? [];
        $listen = is_array($events) ? ($events['listen'] ?? []) : [];
        if (!is_array($listen)) {
            throw InvalidConfigurationException::at('events.listen', 'expected [Event::class => [Listener::class, …]]');
        }
        foreach ($listen as $event => $listeners) {
            foreach (is_array($listeners) ? $listeners : [$listeners] as $listener) {
                if (!is_string($event) || (!is_string($listener) && !is_callable($listener) && !is_array($listener))) {
                    throw InvalidConfigurationException::at('events.listen', 'expected [Event::class => [Listener::class, …]]');
                }
                /** @var callable|string|array{class-string, string} $listener */
                $dispatcher->listen($event, $listener);
            }
        }
        foreach ($this->eventListeners as [$names, $listener, $priority]) {
            $dispatcher->listen($names, $listener, $priority);
        }

        return $dispatcher;
    }

    private function installHandler(): void
    {
        /** @var HttpKernel|null $kernel per-worker */
        $kernel = null;
        $this->runtime->configureContainer(function (WorkerContext $worker) use (&$kernel): void {
            Facade::setContainer($worker->container);
            $this->workerContainer = $worker->container;
            // The kernel must exist before anything that may suspend (module warm-up opens
            // connections): OpenSwoole already dispatches requests to a worker whose WorkerStart
            // callback is suspended on I/O.
            $kernel = $this->makeKernel($worker->container);
            $worker->container->instance(HttpKernel::class, $kernel);
            $worker->container->instance(Router::class, $this->router);
            $this->registerModules($worker);
            if ($this->metrics !== null) {
                $metrics = $this->metrics;
                $this->metricsTimer = Timers::tick(1000, function () use ($metrics): void {
                    $metrics->publish($this->workerGauges());
                });
            }
        });
        $this->runtime->onWorkerStop(function (): void {
            if ($this->metricsTimer !== null) {
                Timers::clear($this->metricsTimer);
                $this->metricsTimer = null;
            }
            $this->metrics?->forget();
            Facade::setContainer(null);
        });
        $metrics = $this->metrics;
        $this->runtime->handle(new HttpServerAdapter($this->http, static function ($incoming, $writer, $context) use (&$kernel, $metrics): void {
            if ($kernel === null) {
                throw new LogicException('HTTP kernel not initialised in this worker');
            }
            $start = hrtime(true);
            try {
                $kernel($incoming, $writer, $context);
            } finally {
                $route = $context->get('http.route');
                $metrics?->observe(is_string($route) ? $route : 'unmatched', $incoming->method(), $writer->statusCode(), (hrtime(true) - $start) / 1e9);
            }
        }, $this->runtime->exceptionHandler()));
    }
}
