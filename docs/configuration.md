# Configuration

`Application::create(array $config)` validates every section at boot; unknown keys, wrong types
and out-of-range values raise `InvalidConfigurationException` naming the dotted path
(for example `server.port`).

| Section | Documented in |
|---|---|
| `server` | [runtime.md](runtime.md) |
| `http` | [requests-responses.md](requests-responses.md) |
| `debug` (bool, default `false`) | exception details in 500 responses — never enable in production |
| `database` | [database.md](database.md) |
| `http_client` | [http-client.md](http-client.md) |
| `redis`, `cache` | [redis.md](redis.md); the queue uses the `redis` section ([background-jobs.md](background-jobs.md)) |

The CPU pool, metrics and health endpoints are enabled with methods (`cpu()`,
`enableMetrics()`, `health()`); see [process-pool.md](process-pool.md) and below.

## Environment variables

Read configuration from the environment once, in the master process, before `run()`:

```php
<?php

use Omnitrix\Application;
use Omnitrix\Config\Env;

Env::load(__DIR__ . '/../.env');   // optional; never overrides real environment variables

$app = Application::create([
    'server' => ['port' => Env::int('PORT', 8080), 'workers' => Env::int('WORKERS', 4)],
    'debug' => Env::bool('APP_DEBUG', false),
    'database' => [
        'host' => Env::required('DB_HOST'),
        'database' => Env::required('DB_NAME'),
        'username' => Env::required('DB_USER'),
        'password' => Env::required('DB_PASSWORD'),
    ],
]);
```

| Method | Returns |
|---|---|
| `Env::load(string $path)` | number of variables loaded; a missing file loads nothing |
| `Env::get(string $name, ?string $default = null)` | the value or the default |
| `Env::required(string $name)` | the value; throws `InvalidConfigurationException` when unset or empty |
| `Env::int(string $name, int $default)` | the integer value; throws on non-integer input |
| `Env::bool(string $name, bool $default)` | `true/false/1/0/yes/no/on/off` |

`.env` syntax: `KEY=value`, `export KEY=value`, `KEY="quoted # kept"`, `# comments`,
`KEY=value # trailing comment`. Keep `.env` out of version control and commit an
`.env.example` with placeholder values instead. Secrets belong in the environment or a secret
manager; the default logger redacts context keys named `password`, `secret`, `token`,
`authorization`, `api_key` and `apikey`.

## Validation rules

`required`, `nullable`, `string`, `int`, `numeric`, `bool`, `array`, `email`, `url`
(http/https only), `uuid`, `min:N`, `max:N` (string length, array size or numeric value),
`between:A,B`, `in:a,b,c`, `regex:/pattern/`. Only fields that have rules are returned.

```php
$data = $request->validate(['email' => 'required|email', 'age' => 'nullable|int|between:13,130']);
```

A failure answers `422` with per-field messages. Outside a request, use
`(new Omnitrix\Validation\Validator($rules))->validate($input)`.

## Observability

```php
use Omnitrix\Config\Env;
use Omnitrix\Security\BearerTokenAuth;
use Omnitrix\Security\Identity;

$app->enableMetrics('/metrics', [
    new BearerTokenAuth(fn (string $t): ?Identity => hash_equals(Env::required('METRICS_TOKEN'), $t) ? new Identity('prometheus') : null),
]);
$app->health('/health');   // /health/live and /health/ready (database and Redis checks when configured)
```

- Request counters and latency histograms are kept in shared memory, so any worker serves
  server-wide numbers in Prometheus text format. Labels: route pattern (never the raw path),
  method, status.
- Gauges: connections, requests, coroutines, event-loop lag (current/max/avg), worker memory,
  idle workers, and the serving worker's database, Redis and HTTP client pool statistics.
- Readiness runs all checks concurrently with a timeout (default 2 s); failures report the
  exception class only. Add your own checks with `health('/health', ['search' => fn (): bool => ...])`.

## Logging

`Application::create($config, $logger)` accepts any PSR-3 logger; `$app->logger()` returns the
logger in use. The default writes one JSON object per line to stderr. To log to a file:

```php
use Omnitrix\Core\Log\JsonLogger;

$app = Application::create($config, new JsonLogger(fopen('/var/log/api/app.log', 'ab')));
```

Point logs at a file or a collector that always drains them: a stderr pipe that nobody reads
blocks workers once the pipe buffer (typically 64 KiB) is full.
