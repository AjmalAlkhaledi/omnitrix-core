# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

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

### Changed

- The package is now `omnitrix/framework`; `omnitrix/omnitrix` is the application skeleton.
- `ext-redis` is required.
- The default logger writes `info` and above (configurable with `app.log_level`).
- `RedisRateLimiter` can be created without a client; it uses the worker's Redis client.

## [0.1.0] - 2026-11-13

### Added

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
