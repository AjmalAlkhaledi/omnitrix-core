# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

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
