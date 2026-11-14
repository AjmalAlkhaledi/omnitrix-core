# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- The framework (`Omnitrix\`) now ships in this package together with the engine
  (`Omnitrix\Core\`, moved to `src/Core`). Applications are created from the
  `omnitrix/omnitrix` template, which requires `omnitrix/core`.
- `ext-redis` is required.

#### Framework

##### Added

- Command line for applications (`Omnitrix\Console\Console`, `php omnitrix`): `serve`, `routes`,
  `about`, `migrate`, `migrate:status`, `migrate:rollback`, `migrate:reset`, `migrate:fresh`,
  `db:seed`, `queue:work`, and `make:controller`, `make:model`, `make:migration`,
  `make:middleware`, `make:job`, `make:seeder`.
- Schema builder (`Schema`, `Blueprint`) and migrations (`Migration`, `Migrator`) for
  MySQL/MariaDB.
- Models (`Omnitrix\Database\Model`) with casts, mass-assignment protection, timestamps,
  pagination and `hasOne`, `hasMany`, `belongsTo` and `belongsToMany` relationships with eager
  loading.
- Seeders (`Omnitrix\Database\Seeding\Seeder`).
- `Config::load()` for a directory of configuration files, and the `app` section
  (`app.env`, `app.debug`, `app.log_level`).
- `Application::withServices()` runs code with the facades outside the HTTP server.

##### Changed

- `ext-redis` is required.
- The default logger writes `info` and above (configurable with `app.log_level`).
- `RedisRateLimiter` can be created without a client; it uses the worker's Redis client.

#### Engine

##### Added

- `JsonLogger` accepts a minimum level (`minimumLevel`); less severe records are discarded.

##### Fixed

- An empty Redis username or password (for example `REDIS_PASSWORD=` in an environment file) is
  treated as "no credentials" instead of sending `AUTH` with an empty password.

## [0.1.0] - 2026-11-13

#### Framework

##### Added

- `Application` with routing (FastRoute), route groups, named routes, middleware and
  configuration validation.
- Requests and responses: JSON, text, HTML, redirects, files, streaming (`Stream`,
  `Response::chunks()`), cookies, typed HTTP exceptions with safe JSON error bodies.
- Facades for the database, Redis, cache, HTTP client, queue, CPU pool and structured
  concurrency (`Async`).
- Validation, bearer-token authentication, authorization, rate limiting (shared memory and
  Redis), security headers, access logs, Prometheus metrics, health and readiness endpoints.
- `Omnitrix\Queue\Worker` for running background jobs.
- `Application::cpu()` for registering CPU-bound jobs in a supervised process pool.
- `Omnitrix\Config\Env` for environment-based configuration.
- `bin/omnitrix-phpunit` for running PHPUnit inside a coroutine container.

#### Engine

##### Added

- Server runtime on OpenSwoole: validated configuration, process and simple modes, worker
  lifecycle hooks, ordered resource release, graceful shutdown and reload.
- Request context with deadlines, attributes and deferred callbacks; failure containment.
- HTTP transport with body limits, request timeouts, request ids, streaming responses and
  `HttpErrorInterface`-based error rendering.
- Structured concurrency: `Async`, `TaskScope`, cancellation tokens, semaphores, bounded
  scheduler.
- MySQL/MariaDB support: PDO/mysqlnd driver, connection pool, query builder, transactions with
  savepoints and deadlock retries, typed errors.
- HTTP client over OpenSwoole coroutine clients with pooling, TLS verification, redirects,
  retries and typed errors.
- Pooled Redis client, JSON cache, reliable Redis queue with retries and dead letters.
- Supervised CPU worker pool, coroutine socket/DNS/filesystem helpers, shared-memory metrics,
  JSON PSR-3 logger, PSR-11 container.
