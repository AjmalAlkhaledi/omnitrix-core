# Contributing

## Development setup

Requirements: PHP 8.3+ with ext-openswoole 26.2+, ext-pdo_mysql (mysqlnd), ext-redis 6.3+,
Composer 2, OpenSSL (for TLS test certificates), and MariaDB 11+ and Redis 7+ binaries on the
`PATH`.

```sh
composer install
bin/services up
make check
bin/services down
```

See [docs/testing.md](docs/testing.md) for the suites and conventions.

| Target | |
|---|---|
| `make lint` / `make lint-fix` | PHP-CS-Fixer check / fix |
| `make stan` | PHPStan (level max with strict rules) |
| `make test-unit`, `make test-integration`, `make test` | PHPUnit suites through `bin/co-phpunit` |

## Guidelines

- Core contains engine code only: no routing, middleware or application conventions, and no
  dependency on `omnitrix/omnitrix`.
- Changes to documented public types follow Semantic Versioning. Mark plumbing that must be
  `public` but is not meant for consumers with `@internal`.
- Behaviour that depends on OpenSwoole or extension quirks gets a test and an entry in
  [docs/platform-notes.md](docs/platform-notes.md).
- Significant design decisions are recorded as ADRs in `docs/adr/`.
- Update `CHANGELOG.md` and regenerate the API reference with `php tools/api_reference.php`.
- Commit messages: imperative mood, a short summary line, details in the body when useful.
