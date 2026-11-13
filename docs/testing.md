# Testing

## Running the suites

```sh
composer install
bin/services up        # MariaDB on 127.0.0.1:33306 and Redis on 127.0.0.1:63790, data in var/
make check             # validate, lint, static analysis, unit and integration tests
bin/services down
```

`bin/services` starts MariaDB and Redis as unprivileged processes from binaries on the
`PATH`; no containers or root access are needed. To use other servers, export the variables in
`.env.example`.

| Suite | Contents |
|---|---|
| `unit` | pure logic and coroutine behaviour without external services |
| `integration` | real OpenSwoole servers (started from `tests/Fixtures/`), MariaDB, Redis, upstream HTTP and TLS servers |

## Coroutine runner

Tests run through `bin/co-phpunit`, which wraps the whole PHPUnit run in one root coroutine
with `HOOK_ALL` enabled. Tests may therefore spawn coroutines, use channels, pooled
connections and hooked I/O directly. OpenSwoole's own log goes to
`var/log/openswoole-tests.log`.

## Conventions

- Non-blocking claims are proven, not assumed: overlap is measured with known delays, and a
  sibling coroutine must keep ticking while the operation under test waits.
- Integration tests use real services, never mocks of them, and clean up the keys, tables and
  processes they create.
- Server fixtures are started as child processes on free ports and stopped with SIGTERM, so
  graceful shutdown is exercised on every run.
- TLS fixtures generate throw-away certificates with `tests/Fixtures/tls/make-certs.sh`.
