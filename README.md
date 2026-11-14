# Omnitrix Core

Omnitrix Core is the runtime engine of the Omnitrix PHP framework (`omnitrix/framework`). It
runs on [OpenSwoole](https://openswoole.com/) and provides the server lifecycle, worker and
request scopes, the HTTP transport, structured concurrency, bounded connection pools, and
coroutine-aware database, HTTP client, Redis, queue, CPU-pool and I/O components.

> **Building an application?** Create one from the skeleton instead:
> `composer create-project omnitrix/omnitrix my-api`. Applications depend on the framework
> (`omnitrix/framework`), which uses this package and exposes it through a stable public API.
> Omnitrix Core is an internal dependency and does not provide application conventions such as
> routing or middleware.

## Requirements

- PHP 8.3+ (NTS) on Linux
- ext-openswoole 26.2+ (built with OpenSSL and HTTP/2 support)
- ext-json, ext-mbstring, ext-pdo
- ext-pdo_mysql on mysqlnd and ext-redis 6.3+ for the database, Redis, cache and queue components

## Installation

```sh
composer require omnitrix/core
```

## Components

| Namespace | Provides |
|---|---|
| `Omnitrix\Core\Runtime` | `ServerRuntime`, worker lifecycle, `RequestContext`, scheduling, shutdown and signal handling |
| `Omnitrix\Core\Http` | HTTP status helpers, `HttpErrorInterface`, server transport (`IncomingRequest`, `ResponseWriter`) |
| `Omnitrix\Core\Concurrency` | `Async`, `TaskScope`, `Task`, `CancellationToken`, `Semaphore` |
| `Omnitrix\Core\Database` | PDO/mysqlnd driver, connection pool, query builder, transactions, typed errors |
| `Omnitrix\Core\HttpClient` | pooled HTTP/1.1 and HTTP/2 client with TLS verification, redirects and retries |
| `Omnitrix\Core\Redis`, `Cache` | pooled phpredis client, JSON cache |
| `Omnitrix\Core\Queue` | reliable Redis queue, producer and consumer |
| `Omnitrix\Core\Process` | supervised CPU worker pool |
| `Omnitrix\Core\IO` | coroutine TCP/UDP sockets, DNS, filesystem helpers |
| `Omnitrix\Core\Observability`, `Log`, `Container` | shared-memory metrics, JSON PSR-3 logger, PSR-11 container |

## Documentation

Architecture, platform notes, design decisions and the API reference of this package are part of
the Omnitrix documentation site project (Architecture → Omnitrix Core).

## Versioning

Core follows Semantic Versioning for the types documented as public. Classes and methods
marked `@internal` may change in any release.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

Omnitrix Core is open-source software licensed under the [MIT license](LICENSE).
