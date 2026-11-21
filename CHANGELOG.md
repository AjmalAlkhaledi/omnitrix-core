# Changelog

All notable changes to this project are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Row and query cache follow **triggers**: writes to a table whose triggers write to cached tables
  (directly, through nested triggers or foreign-key cascades) mark those tables for the write and
  retire all their entries on commit. Triggers calling procedures retire every cached table.
- `/metrics` reports the pool and cache statistics of every worker, not only the worker that
  served the scrape.
- **Redis Cluster** (`redis.cluster` seed nodes): client, cache, queue (per-queue hash tag), rate
  limiter and the database row/query cache (per-table hash tag, restart check per node).
- MySQL 8.4 LTS in CI next to 8.0.
- **PostgreSQL** driver (`driver => 'pgsql'`) on OpenSwoole's coroutine PostgreSQL client (OpenSwoole
  built `--with-postgres`; pdo_pgsql would block the worker): query builder (identity keys through `RETURNING`,
  `UPDATE`/`DELETE` with `ORDER BY`/`LIMIT` through `ctid`), schema builder and migrations (each
  migration runs in a transaction), `migrate` creating a missing database, error classification by
  SQLSTATE, `statement_timeout`, and the row/query cache (foreign keys, PL/pgSQL triggers, text
  tags for deterministic collations). `insertGetId()` takes the key column (default `id`).

### Changed

- Per-worker metrics (`omnitrix_worker_coroutines`, `omnitrix_event_loop_lag_*`,
  `omnitrix_worker_memory_bytes`) carry a `worker` label.
- Requests failing because a database, Redis or connection pool is unavailable answer `503` with
  `Retry-After: 1` instead of `500`.
- Row cache keys carry the table's hash tag (`{conn:table}:pk:v`); the cache starts empty after the
  upgrade. Redis connection callbacks are typed `Redis|RedisCluster`.

### Fixed

- `->cache()` on a non-key condition without `orderBy()` was ignored for tables that also have a
  row cache.
- Two `Database` instances with the same connection name in one coroutine shared transaction
  state and the pinned connection.

## [0.2.0] - 2026-11-21

Tried as 0.2.0-beta.1 (2026-11-21) in an application from the template before this release.

0.x releases may change behaviour between minor versions: applications pinned to `^0.1` stay on
0.1.x; the application template moves to `^0.2` with this release.

### Added

- Primary-key row cache for MySQL/MariaDB (`cache` option of a database connection, stored in
  Redis). Lookups by key — `find()`, `findMany()`, `where('id', …)->first()`, `whereIn('id', …)` and
  `belongsTo` relations — are served from it; every insert, update and delete invalidates exactly
  the affected primary keys, including rows changed by `ON DELETE/UPDATE CASCADE | SET NULL`
  foreign keys, with no application code. Rows are marked before a write and refreshed after the
  outermost transaction ends, so no reader is served a value older than a committed write.
  Absent rows are cached until an insert into the table; schema changes retire a table's
  entries.
- Query result cache: `->cache($seconds)` on queries that filter with `=`/`IN` on a column listed
  in `cache.queries`; invalidated by writes to rows holding a filtered value (before or after the
  write), compared by the column collation, not by writes to the rest of the table.
- Cascades through composite foreign keys, cache metrics in `/metrics`, and the cache
  configuration is validated when the application is created.
- `afterCommit()` on connections and the `DB` facade, with nested-transaction semantics.
- Reliability: writes are refused when the cache cannot be marked (`strict`, on by default); a
  Redis restart or failover (new `run_id`) retires every cached table before use; marks of long
  transactions are renewed; rollbacks (including deadlock retries) release their marks without
  invalidating; commits with an unknown outcome invalidate.
- MySQL 8 support in `bin/services` (`OMNITRIX_TEST_DB_ENGINE=mysql`) and a MySQL job in CI.
- `tools/bench/db_cache_bench.php` to measure the caches on representative hardware.
- `DB::writing()` for raw SQL writes to cached tables, `DB::invalidate()` for rows changed
  outside the application and `Database::flushCache()` for table-level changes.
- Cache statistics per connection (`Database::cacheStats()`): hits, misses, bypassed reads, errors.

### Changed

- A write refused by the row cache in strict mode (`CacheUnavailableException`) answers
  `503 Service Unavailable` with `Retry-After: 1` instead of 500: it did not run and can be retried.

### Fixed

- `/metrics` output is valid Prometheus text: one `# TYPE` line per metric family (it named every
  labelled series before), all series of a family on adjacent lines, and `*_total` families typed
  as counters.

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
