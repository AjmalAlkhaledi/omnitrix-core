# Getting Started

```sh
composer require omnitrix/omnitrix
```

Create `public/server.php`:

```php
<?php

declare(strict_types=1);

use Omnitrix\Application;
use Omnitrix\Config\Env;
use Omnitrix\Http\Request;
use Omnitrix\Http\Response;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$app = Application::create([
    'server' => ['host' => Env::get('HOST', '0.0.0.0') ?? '0.0.0.0', 'port' => Env::int('PORT', 8080), 'workers' => Env::int('WORKERS', 4)],
    'http' => ['max_body_size' => 1024 * 1024, 'request_timeout' => 30],
    'debug' => Env::bool('APP_DEBUG', false),
]);

$app->get('/api/users/{id:\d+}', static function (Request $request): Response {
    return Response::json(['id' => $request->routeInt('id')]);
});

$app->post('/api/users', static function (Request $request): Response {
    $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email']);

    return Response::json(['data' => $data], 201);
});

$app->run();
```

```sh
php public/server.php
curl -s http://127.0.0.1:8080/api/users/10
curl -s -X POST -H 'Content-Type: application/json' \
     -d '{"name":"Ann","email":"ann@example.com"}' http://127.0.0.1:8080/api/users
```

Stop with `SIGTERM` (in-flight requests finish); reload workers with `SIGUSR1`.

Out of the box every response carries a request id, errors are JSON with safe messages, bodies
are size-limited, requests time out, shutdown is graceful and logs are structured JSON.

Next steps:

- [routing.md](routing.md) and [middleware.md](middleware.md) — routes, groups, middleware
- [requests-responses.md](requests-responses.md) — request accessors, responses, streaming
- [database.md](database.md), [redis.md](redis.md), [http-client.md](http-client.md)
- [concurrency.md](concurrency.md) — running independent I/O in parallel inside a request
- [background-jobs.md](background-jobs.md), [process-pool.md](process-pool.md)
- [configuration.md](configuration.md), [deployment.md](deployment.md)
