<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('UTC');

if (!extension_loaded('openswoole')) {
    fwrite(STDERR, "The Omnitrix test suite requires the openswoole extension (run via bin/co-phpunit).\n");
    exit(1);
}

if (OpenSwoole\Coroutine::getCid() < 1) {
    fwrite(STDERR, "Tests must run inside a coroutine container. Use bin/co-phpunit (or `make test`).\n");
    exit(1);
}
