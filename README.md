# Omnitrix

Omnitrix is an asynchronous PHP framework for building HTTP APIs on
[OpenSwoole](https://openswoole.com/). Every request runs in its own coroutine; database
queries, Redis commands, outbound HTTP calls and file I/O suspend only the request that waits,
so a small number of long-lived worker processes serves many concurrent requests.

```php
<?php

use Omnitrix\Application;
use Omnitrix\Facades\Async;
use Omnitrix\Facades\DB;
use Omnitrix\Facades\Http;
use Omnitrix\Http\Request;

require __DIR__ . '/vendor/autoload.php';

$app = Application::create([
    'server' => ['port' => 8080, 'workers' => 4],
    'database' => ['host' => '127.0.0.1', 'database' => 'app', 'username' => 'app', 'password' => getenv('DB_PASSWORD') ?: ''],
]);

$app->get('/users/{id:\d+}', function (Request $request): array {
    $id = $request->routeInt('id');

    return Async::all([
        'user' => fn () => DB::table('users')->where('id', $id)->first(),
        'orders' => fn () => DB::table('orders')->where('user_id', $id)->count(),
        'score' => fn () => Http::timeout(2)->get("https://scores.example.com/users/{$id}")->json(),
    ], timeout: 3.0);
});

$app->run();
```

## Features

- **HTTP**: FastRoute-based routing with groups and named routes, middleware pipeline, JSON
  requests and responses, streaming, cookies, request ids, body limits, request timeouts and
  safe JSON error responses.
- **Concurrency**: structured fan-out (`Async::all`, `allSettled`, `race`, `withTimeout`,
  `scope`) with cancellation and bounded parallelism.
- **Data access**: pooled MySQL/MariaDB connections with a query builder and transactions;
  pooled Redis client and JSON cache; outbound HTTP client with pooling, retries, redirects
  and TLS verification.
- **Background work**: Redis-backed job queue with retries, delays and dead letters; a CPU
  process pool for computation that must not block request workers.
- **Production**: validation, bearer-token authentication, authorization, rate limiting,
  security headers, access logs, Prometheus metrics, health and readiness endpoints, graceful
  shutdown and reload.

## Requirements

PHP 8.3+, ext-openswoole 26.2+, Linux. ext-pdo_mysql (mysqlnd) and ext-redis are needed only
for the database and Redis features. See [docs/installation.md](docs/installation.md).

## Installation

```sh
composer require omnitrix/omnitrix
```

Then follow [docs/getting-started.md](docs/getting-started.md).

## Documentation

| Topic | |
|---|---|
| Basics | [Installation](docs/installation.md) · [Getting started](docs/getting-started.md) · [Configuration](docs/configuration.md) |
| HTTP | [Routing](docs/routing.md) · [Middleware](docs/middleware.md) · [Requests and responses](docs/requests-responses.md) |
| Data | [Database](docs/database.md) · [Redis and cache](docs/redis.md) · [HTTP client](docs/http-client.md) |
| Concurrency | [Concurrency](docs/concurrency.md) · [Background jobs](docs/background-jobs.md) · [CPU pool](docs/process-pool.md) · [Sockets and files](docs/filesystem.md) |
| Operations | [Runtime](docs/runtime.md) · [Deployment](docs/deployment.md) · [Troubleshooting](docs/troubleshooting.md) · [Testing](docs/testing.md) |
| Reference | [Architecture](docs/architecture.md) · [API reference](docs/api-reference.md) · [Benchmarks](benchmarks/README.md) |

## Packages

Omnitrix is built on Omnitrix Core
(`omnitrix/core`), the runtime engine that provides the server lifecycle, connection pools,
drivers and concurrency primitives. Applications only depend on `omnitrix/omnitrix`; see
[docs/architecture.md](docs/architecture.md) for the boundary between the two packages.

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) for the development setup and [SECURITY.md](SECURITY.md)
for reporting vulnerabilities.

## License

Omnitrix is open-source software licensed under the [MIT license](LICENSE).
