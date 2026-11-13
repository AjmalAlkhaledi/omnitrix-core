# Architecture

Omnitrix is distributed as two packages:

| Package | Namespace | Role |
|---|---|---|
| `omnitrix/omnitrix` (this repository) | `Omnitrix\` | the framework applications build on: `Application`, routing, middleware, requests and responses, validation, security, rate limiting, observability endpoints, facades, queue workers |
| `omnitrix/core` | `Omnitrix\Core\` | the engine underneath: server runtime and worker lifecycle, request context, HTTP transport, concurrency primitives, connection pools, database, HTTP client, Redis, cache, queue storage, CPU process pool, coroutine I/O |

Applications depend on `omnitrix/omnitrix` only. Core is installed with it, configured through
`Application::create()` and the `Application` methods, and reached through the framework's
facades. Core never depends on the framework.

```
request ─▶ OpenSwoole Http\Server (process mode) ─▶ worker coroutine          ┐
           request context bound, failures contained, deferred cleanup       │ Core
           HTTP transport: body limit, timeout, request id                    ┘
           HttpKernel: Router (FastRoute) ─▶ middleware pipeline ─▶ handler   ┐
           handler uses: DB / Http / Redis / Cache / Queue / Cpu facades,     │ Framework
                         Async                                                ┘
                          │ pooled, owned per coroutine, bounded, cancellable
                          ▼
           MariaDB/MySQL (PDO + mysqlnd) · upstream APIs · Redis · CPU pool processes
```

## Framework modules

| Namespace | Contents |
|---|---|
| `Omnitrix` | `Application` (configuration, routes, middleware, lifecycle hooks, CPU pool, metrics, health) |
| `Omnitrix\Http` | `Request`, `Response`, `Stream`, `Cookie`, `HttpKernel`, exceptions, middleware pipeline |
| `Omnitrix\Routing` | `Router`, `Route`, handler resolution |
| `Omnitrix\Facades` | `DB`, `Http`, `Redis`, `Cache`, `Queue`, `Cpu`, `Async` |
| `Omnitrix\Validation` | `Validator`, `ValidationException` |
| `Omnitrix\Security` | `BearerTokenAuth`, `Authorize`, `Identity`, `SecurityHeaders` |
| `Omnitrix\RateLimit` | `RateLimit` middleware, shared-memory and Redis limiters |
| `Omnitrix\Observability` | `AccessLog`, health checks |
| `Omnitrix\Queue` | `Worker` |
| `Omnitrix\Config` | `Env` |

## Core types that appear in the public API

Some values the framework returns or throws are defined in Core. They are stable, documented
types; applications use them but do not construct the underlying services:

| Type | Where you meet it |
|---|---|
| `Omnitrix\Core\Runtime\Context\RequestContext` | `$request->context()` |
| `Omnitrix\Core\Runtime\Worker\WorkerContext` | arguments of `onWorkerStart`, `onWorkerStop`, `configureContainer` |
| `Omnitrix\Core\Runtime\Config\InvalidConfigurationException` | thrown by `Application::create()` and `Env` |
| `Omnitrix\Core\Database\DatabaseManager`, `Database`, `Query\Builder`, `Query\Expression`, `Exception\*` | `DB` facade |
| `Omnitrix\Core\HttpClient\PendingRequest`, `Response`, `Exception\*` | `Http` facade |
| `Omnitrix\Core\Redis\RedisClient`, `Omnitrix\Core\Redis\Exception\*` | `Redis` facade |
| `Omnitrix\Core\Cache\Cache` | `Cache::store()` |
| `Omnitrix\Core\Concurrency\TaskScope`, `Task`, `Outcome`, `CancellationToken`, `TimeoutException`, `CancelledException` | `Async` facade |
| `Omnitrix\Core\Process\CpuException` and subclasses | `Cpu::run()` |
| `Omnitrix\Core\Queue\Job` | second argument of queue handlers |
| `Omnitrix\Core\Log\JsonLogger` | default logger; can be passed to `Application::create()` |
| `Omnitrix\Core\IO\*` | optional coroutine socket, DNS and file helpers ([filesystem.md](filesystem.md)) |

Everything else in Core — the server runtime, transport adapter, pools, drivers, schedulers
and process plumbing — is an implementation detail and may change between Core releases
without notice. Classes and methods marked `@internal` in either package are not part of the
public API.

## Design principles

- **One coroutine per request, cooperative I/O everywhere.** OpenSwoole runtime hooks make PDO,
  phpredis, sockets and file access suspend only the calling coroutine.
- **Owned, bounded resources.** Every connection belongs to one coroutine at a time; pools,
  fan-out and CPU queues have explicit limits and fail with typed exceptions instead of
  queueing without bound.
- **Structured concurrency.** Child tasks never outlive the operation that started them;
  timeouts and failures cancel siblings.
- **Fail closed, report safely.** Configuration is validated at boot; client-visible errors
  carry safe messages and a request id; details stay in the logs.
- **Worker-scoped state.** Services are created per worker after the fork; nothing that holds
  a socket crosses process boundaries.

The engine's internal design is documented in the Omnitrix Core repository.
