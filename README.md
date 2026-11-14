# Omnitrix Core

The Omnitrix framework and its runtime engine: asynchronous PHP APIs on
[OpenSwoole](https://openswoole.com/). Every request runs in its own coroutine; database queries,
Redis commands, outbound HTTP calls and file I/O suspend only the request that waits, so a few
long-lived worker processes serve many concurrent requests.

> **Building an application?** Create one from the application template; it requires this
> package:
>
> ```sh
> composer create-project omnitrix/omnitrix my-api
> ```

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

## Requirements

PHP 8.3+, ext-openswoole 26.2+, ext-redis (phpredis) 6.3+ and ext-pdo_mysql on Linux.
Installing PHP and these extensions is up to you; Composer refuses to install the package when
they are missing.

## What is inside

| Namespace | Provides |
|---|---|
| `Omnitrix\` | the framework: `Application`, routing, middleware, requests and responses, validation, security, rate limiting, observability, facades, models and migrations, the `php omnitrix` console, queue workers |
| `Omnitrix\Core\` | the engine: server runtime and worker lifecycle, request context, HTTP transport, structured concurrency, connection pools, MySQL/MariaDB driver and query builder, HTTP client, Redis, cache, queue storage, CPU process pool, coroutine I/O |

Applications use the `Omnitrix\` API and the documented `Omnitrix\Core\` types it returns.
Classes and methods marked `@internal` are implementation details and may change in any release.

## Documentation

https://ajmalalkhaledi.github.io/omnitrix-docs/

## Related repositories

| Package | Role |
|---|---|
| `omnitrix/omnitrix` | the application template (`composer create-project omnitrix/omnitrix my-api`) |
| `omnitrix/core` | this package |
| `omnitrix/installer` | the `omnitrix new` command |

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md).

## License

Omnitrix is open-source software licensed under the [MIT license](LICENSE).
