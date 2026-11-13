# API Reference

Public classes and methods, generated from the source with `php tools/api_reference.php`. Narrative documentation lives in the other files of `docs/`.

## Omnitrix

### Application (class)

The developer-facing application: configuration, routes, middleware, run.

- `static create(array $config, ?Psr\Log\LoggerInterface $logger = NULL, ?Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface $exceptionHandler = NULL): Omnitrix\Application`
- `get(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `post(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `put(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `patch(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `delete(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `options(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `any(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `map(array $methods, string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `group(string $prefix, callable $define, array $middleware = …): Omnitrix\Application`
- `routes(string $file): Omnitrix\Application` — Loads a route file. The file receives `$app` (this application) and `$router` in scope,
- `middleware(Omnitrix\Http\Middleware\MiddlewareInterface|callable ...$middleware): Omnitrix\Application` — Global middleware applied to every route, in order.
- `router(): Omnitrix\Routing\Router`
- `logger(): Psr\Log\LoggerInterface` — The application logger (PSR-3). Structured JSON on stderr unless one was passed to create().
- `exceptionHandler(): Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface` — Receives every exception caught at a coroutine or request boundary.
- `config(): array`
- `isDebug(): bool`
- `onWorkerStart(callable $listener): Omnitrix\Application`
- `onWorkerStop(callable $listener): Omnitrix\Application`
- `configureContainer(callable $configurator): Omnitrix\Application`
- `build(): OpenSwoole\Http\Server` — Builds the server without starting it.
- `run(): void` — Starts the server (blocking).
- `stop(): void`
- `enableMetrics(string $path = '/metrics', array $middleware = …): Omnitrix\Application` — Records request counts and latency histograms (shared across workers) and serves them in
- `health(string $prefix = '/health', array $checks = …, float $timeout = 2.0): Omnitrix\Application` — Registers `{$prefix}/live` (process is up) and `{$prefix}/ready` (dependencies answer).
- `cpu(array $jobs, int $workers = 2, float $timeout = 30.0, int $maxPending = 64): Omnitrix\Application` — Enables the CPU worker pool: `$workers` dedicated processes, supervised and restarted by

## Omnitrix\Config

### Env (class)

Environment variables for configuration. Read once at boot (in the master) to build the

- `static load(string $path): int` — Loads a .env file into the process environment without overriding existing variables.
- `static get(string $name, ?string $default = NULL): ?string`
- `static required(string $name): string`
- `static int(string $name, int $default): int`
- `static bool(string $name, bool $default): bool`

## Omnitrix\Facades

### Async (class)

Public entry point for structured concurrency (see docs/concurrency.md).

- `static all(array $tasks, ?float $timeout = NULL, ?int $concurrency = NULL): array`
- `static allSettled(array $tasks, ?float $timeout = NULL, ?int $concurrency = NULL): array`
- `static race(array $tasks, ?float $timeout = NULL): mixed`
- `static withTimeout(callable $body, float $timeout): mixed`
- `static scope(callable $body, ?int $concurrency = NULL): mixed`
- `static sleep(float $seconds): void`

### Cache (class)

Application cache (see docs/redis.md).

- `static store(): Omnitrix\Core\Cache\Cache`
- `static get(string $key, mixed $default = NULL): mixed`
- `static set(string $key, mixed $value, ?int $ttl = NULL): bool`
- `static add(string $key, mixed $value, ?int $ttl = NULL): bool`
- `static has(string $key): bool`
- `static delete(string ...$keys): int`
- `static remember(string $key, ?int $ttl, callable $compute): mixed`
- `static increment(string $key, int $by = 1): int`

### Cpu (class)

CPU worker pool (see docs/process-pool.md).

- `static pool(): Omnitrix\Core\Process\CpuPool`
- `static run(string $job, array $args = …, ?float $timeout = NULL): mixed`

### DB (class)

Database access (see docs/database.md).

- `static manager(): Omnitrix\Core\Database\DatabaseManager`
- `static connection(?string $name = NULL): Omnitrix\Core\Database\Database`
- `static table(string $table): Omnitrix\Core\Database\Query\Builder`
- `static query(): Omnitrix\Core\Database\Query\Builder`
- `static select(string $sql, array $bindings = …): array`
- `static selectOne(string $sql, array $bindings = …): ?array`
- `static execute(string $sql, array $bindings = …): int`
- `static insert(string $sql, array $bindings = …): int`
- `static transaction(callable $callback, int $attempts = 1): mixed`
- `static raw(string $sql, array $bindings = …): Omnitrix\Core\Database\Query\Expression`

### Facade (abstract class)

Static accessors over worker-scoped services.

- `static setContainer(?Psr\Container\ContainerInterface $container): void`
- `static hasContainer(): bool`

### Http (class)

Outbound HTTP (see docs/http-client.md).

- `static client(): Omnitrix\Core\HttpClient\HttpClient`
- `static request(): Omnitrix\Core\HttpClient\PendingRequest`
- `static withHeaders(array $headers): Omnitrix\Core\HttpClient\PendingRequest`
- `static withToken(string $token, string $type = 'Bearer'): Omnitrix\Core\HttpClient\PendingRequest`
- `static timeout(float $seconds): Omnitrix\Core\HttpClient\PendingRequest`
- `static retry(int $times, bool $onTimeout = false): Omnitrix\Core\HttpClient\PendingRequest`
- `static get(string $url, array $query = …): Omnitrix\Core\HttpClient\Response`
- `static post(string $url, mixed $json = NULL): Omnitrix\Core\HttpClient\Response`
- `static put(string $url, mixed $json = NULL): Omnitrix\Core\HttpClient\Response`
- `static patch(string $url, mixed $json = NULL): Omnitrix\Core\HttpClient\Response`
- `static delete(string $url): Omnitrix\Core\HttpClient\Response`
- `static head(string $url): Omnitrix\Core\HttpClient\Response`

### Queue (class)

Background jobs (see docs/background-jobs.md).

- `static producer(): Omnitrix\Core\Queue\QueueProducer`
- `static dispatch(string $name, array $payload = …, float $delay = 0.0, ?string $queue = NULL, ?int $maxAttempts = NULL): string`
- `static stats(?string $queue = NULL): array`

### Redis (class)

Redis access (see docs/redis.md). Typed helpers for common commands; `command()` for the

- `static client(): Omnitrix\Core\Redis\RedisClient`
- `static get(string $key): ?string`
- `static set(string $key, string $value, ?int $ttl = NULL): bool`
- `static delete(string ...$keys): int`
- `static increment(string $key, int $by = 1): int`
- `static expire(string $key, int $seconds): bool`
- `static command(string $name, array $args = …): mixed`
- `static withConnection(callable $fn): mixed`
- `static pipeline(callable $commands): array`
- `static transaction(callable $commands): array`
- `static publish(string $channel, string $message): int`

## Omnitrix\Http

### Cookie (class)

Immutable Set-Cookie description.

- `__construct(string $name, string $value, int $expires = 0, string $path = '/', string $domain = '', bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax')`
- `static forget(string $name, string $path = '/', string $domain = ''): Omnitrix\Http\Cookie` — A cookie that instructs the client to delete `$name`.

### ErrorResponseFactory (class)

Converts any throwable into a JSON error {@see Response}.

- `__construct(bool $debug = false)`
- `fromThrowable(Throwable $e, string $requestId): Omnitrix\Http\Response`
- `isDebug(): bool`

## Omnitrix\Http\Exception

### BadRequestException (class)

- `__construct(string $message = 'The request could not be understood', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

### HttpException (class)

An exception that maps directly to an HTTP response. The message is considered safe to show

- `__construct(int $status, string $message = '', array $headers = …, array $details = …, ?Throwable $previous = NULL)`
- `status(): int`
- `headers(): array`
- `details(): array`

### MethodNotAllowedException (class)

- `__construct(string $message = 'Method not allowed', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

### NotFoundException (class)

- `__construct(string $message = 'Resource not found', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

### PayloadTooLargeException (class)

- `__construct(string $message = 'Request body exceeds the allowed size', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

### ServiceUnavailableException (class)

- `__construct(string $message = 'Service temporarily unavailable', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

### UnsupportedMediaTypeException (class)

- `__construct(string $message = 'Unsupported Content-Type', array $headers = …, array $details = …, ?Throwable $previous = NULL)`

## Omnitrix\Http

### HttpKernel (class)

The per-worker HTTP kernel: request → router → middleware pipeline → handler → response.

- `__construct(Omnitrix\Routing\Router $router, Omnitrix\Routing\HandlerResolver $resolver, Omnitrix\Http\ErrorResponseFactory $errors, Omnitrix\Core\Runtime\Exception\ExceptionHandlerInterface $exceptionHandler)`
- `middleware(Omnitrix\Http\Middleware\MiddlewareInterface|callable ...$middleware): Omnitrix\Http\HttpKernel` — Global middleware, run for every matched route in registration order.
- `router(): Omnitrix\Routing\Router`
- `__invoke(Omnitrix\Core\Http\Server\IncomingRequest $incoming, Omnitrix\Core\Http\Server\ResponseWriter $writer, Omnitrix\Core\Runtime\Context\RequestContext $context): void` — Entry point for the server adapter.
- `handle(Omnitrix\Http\Request $request): Omnitrix\Http\Response` — Produces a Response for a Request (also usable without a server, e.g. in tests).

## Omnitrix\Http\Middleware

### MiddlewareInterface (interface)

Middleware wraps the rest of the pipeline. Call `$next($request)` to continue, or return a

- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`

### Next (class)

The continuation handed to middleware. Calling it runs the rest of the pipeline.

- `__construct(Closure $continuation)`
- `__invoke(Omnitrix\Http\Request $request): Omnitrix\Http\Response`

### Pipeline (class)

Compiles a middleware list and a final handler into one closure, once. Dispatch is then a

- `static compile(array $middleware, callable $handler): Closure`

## Omnitrix\Http

### Request (class)

The request object handed to middleware and route handlers.

- `context(): Omnitrix\Core\Runtime\Context\RequestContext`
- `id(): string`
- `method(): string`
- `isMethod(string $method): bool`
- `path(): string`
- `queryString(): string`
- `query(): array`
- `queryParam(string $name, mixed $default = NULL): mixed`
- `headers(): array`
- `header(string $name, ?string $default = NULL): ?string`
- `hasHeader(string $name): bool`
- `bearerToken(): ?string` — Bearer token from the Authorization header, or null.
- `cookies(): array`
- `cookie(string $name, ?string $default = NULL): ?string`
- `contentType(): ?string`
- `isJson(): bool`
- `body(): string`
- `json(): mixed`
- `jsonObject(): array`
- `form(): array`
- `files(): array`
- `remoteAddress(): ?string`
- `protocolVersion(): string`
- `input(string $name, mixed $default = NULL): mixed` — A single input value: JSON object field (for JSON requests), then form field, then
- `all(): array` — All input merged (query < form < JSON object), later sources overriding earlier ones.
- `validate(array $rules): array` — Validates `all()` input against rule strings and returns only the validated fields.
- `routePattern(): ?string` — The matched route pattern (bounded cardinality; safe as a metrics label), or null.
- `route(string $name, mixed $default = NULL): mixed` — Route parameter (e.g. `{id}`) as the raw URL segment, or `$default`.
- `routeInt(string $name): int` — Route parameter cast to int; throws a 404 when it is not an integer.
- `routeParams(): array`
- `routeName(): ?string`
- `setAttribute(string $name, mixed $value): void`
- `attribute(string $name, mixed $default = NULL): mixed`
- `hasAttribute(string $name): bool`
- `attributes(): array`

### Response (class)

The response value returned by handlers and middleware.

- `__construct(string $body = '', int $status = 200, array $headers = …)`
- `static json(mixed $data, int $status = 200, int $flags = 320): Omnitrix\Http\Response`
- `static text(string $body, int $status = 200): Omnitrix\Http\Response`
- `static html(string $body, int $status = 200): Omnitrix\Http\Response`
- `static noContent(): Omnitrix\Http\Response`
- `static redirect(string $location, int $status = 302): Omnitrix\Http\Response`
- `static file(string $path, ?string $contentType = NULL, ?string $downloadName = NULL): Omnitrix\Http\Response` — Sends a file with sendfile(2).
- `static stream(callable $producer, int $status = 200, string $contentType = 'application/octet-stream'): Omnitrix\Http\Response` — Streams through a callback that writes chunks with {@see Stream::write()}; the
- `static chunks(iterable $chunks, int $status = 200, string $contentType = 'application/octet-stream'): Omnitrix\Http\Response` — Streams every string yielded by the iterable (generators welcome) as a chunk.
- `status(int $status): Omnitrix\Http\Response`
- `header(string $name, string $value): Omnitrix\Http\Response`
- `headers(array $headers): Omnitrix\Http\Response`
- `withoutHeader(string $name): Omnitrix\Http\Response`
- `cookie(Omnitrix\Http\Cookie $cookie): Omnitrix\Http\Response`
- `getStatus(): int`
- `getHeader(string $name): ?string`
- `getHeaders(): array`
- `getCookies(): array`
- `bodyType(): int`
- `getBody(): string`
- `getFile(): ?string`
- `getStream(): ?Closure`
- `getChunks(): ?iterable`

### Stream (class)

The body of a streaming response, handed to the producer of {@see Response::stream()}.

- `write(string $chunk): bool` — Sends a chunk; false when the client is gone.
- `clientGone(): bool` — True once a write reported that the client disconnected.
- `bytesWritten(): int` — Bytes sent so far.

## Omnitrix\Observability

### AccessLog (class)

One structured log line per request: method, route pattern, path, status, duration, request

- `__construct(Psr\Log\LoggerInterface $logger, array $skipPaths = …)`
- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`

### HealthCheck (class)

Liveness and readiness. Readiness runs every registered check concurrently with a timeout;

- `__construct(float $timeout = 2.0)`
- `add(string $name, callable $check): Omnitrix\Observability\HealthCheck`
- `live(): Omnitrix\Http\Response`
- `ready(): Omnitrix\Http\Response`

## Omnitrix

### Omnitrix (class)

Framework identity constants.


## Omnitrix\Queue

### Worker (class)

A background job consumer process.

- `__construct(array $config, string $queue = 'default', int $concurrency = 4, float $jobTimeout = 60.0, float $backoffBase = 1.0, ?Psr\Log\LoggerInterface $logger = NULL)`
- `handle(string $job, callable $handler): Omnitrix\Queue\Worker` — Registers the handler for one job name.
- `run(): int` — Processes jobs until terminated; returns 0 after a graceful stop.
- `stats(): array`

## Omnitrix\RateLimit

### RateLimit (class)

Rate limiting middleware. Keyed by authenticated identity when present, otherwise by client

- `__construct(Omnitrix\RateLimit\RateLimiterInterface $limiter, int $limit, int $windowSeconds = 60, ?callable $key = NULL, string $name = 'default', ?callable $onError = NULL)`
- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`

### RateLimitResult (class)

- `__construct(bool $allowed, int $limit, int $remaining, int $retryAfter)`

### RateLimiterInterface (interface)

Counts hits per key in fixed windows.

- `hit(string $key, int $limit, int $windowSeconds): Omnitrix\RateLimit\RateLimitResult` — Records one hit and returns the decision.

### RedisRateLimiter (class)

Fixed-window limiter shared by every worker and server (INCR + EXPIRE in one Lua call, so the

- `__construct(?Omnitrix\Core\Redis\RedisClient $redis = NULL, string $prefix = 'ratelimit:')`
- `hit(string $key, int $limit, int $windowSeconds): Omnitrix\RateLimit\RateLimitResult`

### TableRateLimiter (class)

Fixed-window limiter in OpenSwoole shared memory: shared by every worker of one server (create

- `__construct(int $maxKeys = 65536)`
- `hit(string $key, int $limit, int $windowSeconds): Omnitrix\RateLimit\RateLimitResult`

## Omnitrix\Routing

### HandlerResolver (class)

Turns a route handler declaration into a `Closure(Request): Response`-like callable, once

- `__construct(?Psr\Container\ContainerInterface $container = NULL)`
- `resolve(mixed $handler): Closure`

### Route (class)

A registered route: methods, path pattern, handler, middleware and an optional name.

- `__construct(array $methods, string $pattern, mixed $handler)`
- `middleware(Omnitrix\Http\Middleware\MiddlewareInterface|callable ...$middleware): Omnitrix\Routing\Route`
- `name(string $name): Omnitrix\Routing\Route`
- `getName(): ?string`
- `getMiddleware(): array`
- `setPipeline(Closure $pipeline): void`
- `getPipeline(): ?Closure`

### RouteMatch (class)

Result of a successful dispatch.

- `__construct(Omnitrix\Routing\Route $route, array $params)`

### Router (class)

Route registration and dispatch on top of FastRoute's GroupCountBased engine.

- `get(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `post(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `put(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `patch(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `delete(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `options(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `any(string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `map(array $methods, string $pattern, mixed $handler): Omnitrix\Routing\Route`
- `group(string $prefix, callable $define, array $middleware = …): void` — Defines routes under a common prefix and middleware set.
- `routes(): array`
- `isCompiled(): bool`
- `compile(): void` — Builds the dispatcher data. Idempotent.
- `dispatch(string $method, string $path): Omnitrix\Routing\RouteMatch`
- `routesFor(string $method): array`
- `pipelineFor(Omnitrix\Routing\Route $route, Closure $factory): Closure` — Returns the route's compiled pipeline, building it with `$factory` on first use.

## Omnitrix\Security

### Authorize (class)

Authorization hook: `Authorize::ability('orders.write')` or a custom policy

- `__construct(callable $policy)`
- `static ability(string $ability): Omnitrix\Security\Authorize`
- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`

### BearerTokenAuth (class)

Authenticates `Authorization: Bearer <token>` through an application-supplied resolver

- `__construct(callable $resolver, bool $optional = false)`
- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`
- `static identity(Omnitrix\Http\Request $request): ?Omnitrix\Security\Identity`

### Identity (class)

The authenticated principal attached to a request by {@see BearerTokenAuth}.

- `__construct(string $id, array $abilities = …, array $claims = …)`
- `can(string $ability): bool`

### SecurityHeaders (class)

Adds conservative security headers suited to JSON APIs (only where the handler did not set them).

- `__construct(array $headers = …, bool $hsts = false)`
- `process(Omnitrix\Http\Request $request, Omnitrix\Http\Middleware\Next $next): Omnitrix\Http\Response`

## Omnitrix\Validation

### ValidationException (class)

422 with field errors: `{"error": {"status": 422, "message": "...", "errors": {field: [messages]}}}`.

- `__construct(array $errors)`

### Validator (class)

Small rule-string validator for request input.

- `__construct(array $rules)`
- `validate(array $input): array`
