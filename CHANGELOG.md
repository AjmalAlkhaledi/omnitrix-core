# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [0.1.0] - 2026-11-13

### Added

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
