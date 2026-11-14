# Contributing

## Development setup

Requirements: PHP 8.3+ with ext-openswoole 26.2+, ext-pdo_mysql (mysqlnd), ext-redis 6.3+,
Composer 2, OpenSSL (for TLS test certificates), and MariaDB 11+ and Redis 7+ binaries on the
`PATH` for the integration tests.

```sh
composer install
bin/services up        # MariaDB on 127.0.0.1:33306 and Redis on 127.0.0.1:63790, data in var/
make check             # composer validate/audit, coding standards, static analysis, all tests
bin/services down
```

`bin/services` runs both servers as unprivileged processes; no containers or root access are
needed. To use existing servers instead, export the variables listed in `.env.example`.

| Target | |
|---|---|
| `make lint` / `make lint-fix` | PHP-CS-Fixer check / fix |
| `make stan` | PHPStan (level max with strict rules) |
| `make test-unit`, `make test-integration`, `make test` | PHPUnit suites |

Tests run through `bin/omnitrix-phpunit`, which executes PHPUnit inside an OpenSwoole coroutine
with runtime hooks enabled. Do not run `vendor/bin/phpunit` directly.

## Layout

```
src/            Omnitrix\        the framework
src/Core/       Omnitrix\Core\   the engine
tests/Framework Omnitrix\Tests\        framework tests (Unit, Integration, Fixtures, Support)
tests/Core      Omnitrix\Core\Tests\   engine tests
benchmarks/     benchmark harness (see benchmarks/README.md)
tools/          API reference generator, router micro-benchmark
```

## Trying changes in an application

Point a project created from `omnitrix/omnitrix` at your checkout without committing the change:

```sh
composer config repositories.omnitrix-core '{"type": "path", "url": "../omnitrix-core", "options": {"symlink": true, "versions": {"omnitrix/core": "0.1.0"}}}'
composer update omnitrix/core
```

## Guidelines

- The engine (`Omnitrix\Core\`) contains no routing, middleware or application conventions and
  never uses the framework (`Omnitrix\`). The framework only uses the engine's documented types.
- Mark plumbing that must be `public` but is not meant for applications with `@internal`.
- Every change comes with tests; integration tests use real services, not mocks of them.
- Behaviour that depends on OpenSwoole or extension quirks gets a test and an entry in the
  platform notes of the documentation site.
- Update the documentation site and `CHANGELOG.md` with user-visible changes. The API reference
  page is generated with `php tools/api_reference.php` (run `npm run api` in the docs project).
- Commit messages: imperative mood, a short summary line, details in the body when useful.
