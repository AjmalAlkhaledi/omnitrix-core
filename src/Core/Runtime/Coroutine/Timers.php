<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Coroutine;

use OpenSwoole\Timer;

use function is_int;
use function max;

/**
 * Thin, typed wrapper around OpenSwoole timers.
 *
 * Exists because the extension's arginfo declares `Timer::clear()` and `Timer::exists()` without
 * parameters although they take the timer id (verified at runtime); static analysis of direct
 * calls therefore fails. Centralising the calls keeps one documented exception.
 */
final class Timers
{
    private function __construct() {}

    /** Schedules `$callback` once after `$ms` milliseconds; returns the timer id or null. */
    public static function after(int $ms, callable $callback): ?int
    {
        $id = Timer::after(max(1, $ms), $callback);

        return is_int($id) ? $id : null;
    }

    /** Schedules `$callback` every `$ms` milliseconds; returns the timer id or null. */
    public static function tick(int $ms, callable $callback): ?int
    {
        $id = Timer::tick(max(1, $ms), $callback);

        return is_int($id) ? $id : null;
    }

    public static function clear(int $timerId): bool
    {
        // @phpstan-ignore arguments.count (extension arginfo omits the id parameter; see class doc)
        return Timer::clear($timerId) === true;
    }

    public static function exists(int $timerId): bool
    {
        // @phpstan-ignore arguments.count (extension arginfo omits the id parameter; see class doc)
        return Timer::exists($timerId) === true;
    }

    public static function clearAll(): void
    {
        Timer::clearAll();
    }
}
