# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [0.1.1] - 2026-11-14

### Added

- `php omnitrix migrate` and `migrate:fresh` offer to create the configured database when it
  does not exist (MySQL error 1049) and continue with the migrations; declining exits without
  changes. With `--no-interaction` the database is created.

## [0.1.0] - 2026-11-14

First public release. The package contains the framework (`Omnitrix\`) and its runtime engine
(`Omnitrix\Core\`); applications are created from the `omnitrix/omnitrix` template.

### Framework

- `Application` with routing (FastRoute), route groups, named routes, middleware, configuration
  validation and `withServices()` for using the services outside the HTTP server.
- Configuration from a directory of files (`Config::load()`), environment variables (`Env`) and
  the `app` section (`env`, `debug`, `log_level`).
- Requests and responses: JSON, text, HTML, redirects, files, streaming (`Stream`,
  `Response::chunks()`), cookies, typed HTTP exceptions with safe JSON error bodies.
- Validation, bearer-token authentication, authorization, rate limiting (shared memory and
  Redis), security headers, access logs, Prometheus metrics, health and readiness endpoints.
- Facades for the database, Redis, cache, HTTP client, queue, CPU pool and structured
  concurrency (`Async`).
- Database: schema builder and migrations with batch tracking, models with casts,
  mass-assignment protection, timestamps, pagination and `hasOne`, `hasMany`, `belongsTo` and
  `belongsToMany` relationships with nested and constrained eager loading, and seeders.
- Command line (`php omnitrix`): `serve`, `routes`, `about`, `migrate`, `migrate:status`,
  `migrate:rollback`, `migrate:reset`, `migrate:fresh`, `db:seed`, `queue:work` and the
  `make:controller`, `make:model`, `make:migration`, `make:middleware`, `make:job` and
  `make:seeder` generators.
- Queue workers (`Omnitrix\Queue\Worker`) and CPU-bound jobs in a supervised process pool
  (`Application::cpu()`).
- `bin/omnitrix-phpunit` for running PHPUnit inside a coroutine container.

### Engine

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
- Pooled Redis client (empty credentials mean no `AUTH`), JSON cache, reliable Redis queue with
  retries and dead letters.
- Supervised CPU worker pool, coroutine socket/DNS/filesystem helpers, shared-memory metrics,
  JSON PSR-3 logger with a minimum level, PSR-11 container.
