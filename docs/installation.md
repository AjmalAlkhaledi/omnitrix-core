# Installation

## Requirements

| Requirement | Version | Notes |
|---|---|---|
| PHP | >= 8.3, NTS | ZTS builds are not needed |
| ext-openswoole | >= 26.2.0 | build with `--enable-openssl --enable-http2 --enable-hook-curl`; `--enable-io-uring` is optional (liburing, kernel >= 5.1) |
| ext-json, ext-mbstring | bundled | |
| ext-pdo, ext-pdo_mysql (mysqlnd) | bundled | only for the database layer; `libmysqlclient`-based builds are **not** coroutine-compatible |
| ext-redis (phpredis) | >= 6.3 | only for Redis, cache, queue and the Redis rate limiter |
| Composer | 2.x | |

Linux is the supported production platform. On kernels older than 5.1 io_uring is not
available; Omnitrix detects this and uses epoll.

## Installing OpenSwoole

```sh
pecl install openswoole-26.2.0
# answer "yes" to openssl, http2 and hook-curl, then enable the extension:
#   extension=openswoole
php --ri openswoole   # prints the version and "hook-curl => enabled"
```

A PIE-managed build (`pie install openswoole/ext-openswoole`) works equally well.

## Installing phpredis

```sh
pecl install redis
# or, for a PHP installed under a custom prefix:
pecl download redis && tar xzf redis-*.tgz && cd redis-*/
phpize && ./configure --with-php-config="$(command -v php-config)" && make
cp modules/redis.so "$(php-config --extension-dir)/"
echo "extension=redis" > "$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')/90-redis.ini"
```

## Installing the framework

```sh
composer require omnitrix/omnitrix
```

Composer installs Omnitrix Core (`omnitrix/core`) as a dependency. Applications never need to
require or configure it directly; see [architecture.md](architecture.md).

Continue with [getting-started.md](getting-started.md).
