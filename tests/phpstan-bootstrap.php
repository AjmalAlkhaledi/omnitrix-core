<?php

declare(strict_types=1);

/*
 * PHPStan reflects internal classes through the running PHP binary. OpenSwoole\Process::alarm()
 * declares `int $type = ITIMER_REAL`, a pcntl constant that this PHP 8.5 build does not define,
 * which makes stub generation throw "Undefined constant ITIMER_REAL". Defining the POSIX values
 * here only affects the analysis process.
 */
foreach (['ITIMER_REAL' => 0, 'ITIMER_VIRTUAL' => 1, 'ITIMER_PROF' => 2] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}
