# Omnitrix Framework

The framework behind Omnitrix applications: asynchronous PHP APIs on
[OpenSwoole](https://openswoole.com/). Every request runs in its own coroutine; database queries,
Redis commands, outbound HTTP calls and file I/O suspend only the request that waits, so a few
long-lived worker processes serve many concurrent requests.

```php
// routes/api.php in an Omnitrix application
$router->get('/users/{id:\d+}', function (Request $request): array {
    $user = User::with('posts')->findOrFail($request->routeInt('id'));

    return Async::all([
        'user' => fn () => $user,
        'followers' => fn () => DB::table('follows')->where('user_id', $user->id)->count(),
        'score' => fn () => Http::timeout(2)->get("https://scores.example.com/users/{$user->id}")->json(),
    ], timeout: 3.0);
});
```

## Getting started

Create an application from the skeleton; it depends on this package:

```sh
composer create-project omnitrix/omnitrix my-api
# or, with the installer:
composer global require omnitrix/installer
omnitrix new my-api
```

```sh
cd my-api
php omnitrix migrate
php omnitrix serve
```

## Requirements

PHP 8.3+, ext-openswoole 26.2+, ext-redis (phpredis) 6.3+ and ext-pdo_mysql on Linux.
Installing PHP and these extensions is up to you; Composer refuses to install the framework
when they are missing.

## Features

- **HTTP**: routing with groups and named routes, middleware, validation, JSON errors with
  request ids, streaming, cookies, body limits and request timeouts.
- **Console**: `php omnitrix serve`, `migrate`, `db:seed`, `queue:work`, `routes`, `about`, and
  generators for controllers, models, migrations, middleware, jobs and seeders.
- **Database**: pooled MySQL/MariaDB connections, query builder, schema migrations, models with
  `hasOne`, `hasMany`, `belongsTo` and `belongsToMany` relationships, eager loading, pagination.
- **Concurrency**: `Async::all`, `allSettled`, `race`, `withTimeout` and scopes with
  cancellation; coroutine HTTP client, Redis, cache and queue; a CPU process pool.
- **Production**: authentication, authorization, rate limiting, security headers, access logs,
  Prometheus metrics, health checks, graceful shutdown and reloads.

## Documentation

The documentation (installation, guides, architecture and the API reference) is published from
the Omnitrix documentation site project.

## Packages

| Package | Role |
|---|---|
| `omnitrix/omnitrix` | application skeleton |
| `omnitrix/framework` | this package |
| `omnitrix/core` | runtime engine used by the framework; applications never require it directly |
| `omnitrix/installer` | the `omnitrix new` command |

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

Omnitrix is open-source software licensed under the [MIT license](LICENSE).
