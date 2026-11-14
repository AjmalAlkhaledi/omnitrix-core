# Contributing

## Development setup

Requirements: PHP 8.3+ with ext-openswoole 26.2+, ext-pdo_mysql (mysqlnd), ext-redis 6.3+,
Composer 2, and MariaDB 11+ and Redis 7+ binaries on the `PATH` for the integration tests.

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

Tests run through `bin/omnitrix-phpunit`, which executes PHPUnit inside an OpenSwoole
coroutine with runtime hooks enabled. Do not run `vendor/bin/phpunit` directly.

## Working on Omnitrix Core at the same time

Changes that need a new Core feature are made in the Core repository first. To develop both
together, point Composer at a local Core checkout (here `../framework`) without committing the
change:

```sh
composer config repositories.omnitrix-core '{"type": "path", "url": "../framework", "options": {"symlink": true, "versions": {"omnitrix/core": "0.1.0"}}}'
composer update omnitrix/core
# ... work ...
git checkout composer.json && composer update omnitrix/core
```

The framework must only use Core's public types; anything marked `@internal` in Core may
change without notice.

## Guidelines

- Every change comes with tests; integration tests use real services, not mocks of them.
- Keep the public API small and documented. Mark framework-only plumbing `@internal`.
- Update the documentation site and `CHANGELOG.md` with user-visible changes. The API reference
  page is generated with `php tools/api_reference.php` (run `npm run api` in the docs project).
- Commit messages: imperative mood, a short summary line, details in the body when useful.

## Continuous integration

`.github/workflows/ci.yml` runs the same checks on PHP 8.3–8.5. Until `omnitrix/core` is
available from a Composer repository, set the repository variable `OMNITRIX_CORE_REPOSITORY`
(for example `owner/omnitrix-core`, optionally with `OMNITRIX_CORE_REF`) so the workflow
installs Core from that checkout.
