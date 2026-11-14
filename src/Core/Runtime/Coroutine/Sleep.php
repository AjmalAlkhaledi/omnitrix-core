<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Coroutine;

use InvalidArgumentException;
use Omnitrix\Core\Concurrency\CancelledException;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

use function max;
use function round;
use function usleep;

/**
 * Cooperative sleep with sub-second resolution.
 *
 * `OpenSwoole\Coroutine::sleep()` is declared `int $seconds` in 26.2 and truncates floats
 * (verified: `sleep(0.5)` returns immediately). Outside a coroutine this falls back to a real
 * `usleep`, inside one it uses a timer so it never depends on `HOOK_SLEEP` being enabled.
 * A sleeping coroutine that gets cancelled wakes with {@see CancelledException}.
 */
final class Sleep
{
    private function __construct() {}

    public static function for(float $seconds): void
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException('Sleep duration must be >= 0');
        }
        if (Coroutine::getCid() < 1) {
            usleep((int) ($seconds * 1_000_000));

            return;
        }

        $ms = (int) max(1, round($seconds * 1000));
        $wake = new Channel(1);
        $timer = Timers::after($ms, static function () use ($wake): void {
            $wake->push(true);
        });
        if ($wake->pop() !== true) {
            // Interrupted by Coroutine::cancel() (see Concurrency\CancellationToken).
            if ($timer !== null) {
                Timers::clear($timer);
            }
            throw new CancelledException('Sleep cancelled');
        }
    }

    public static function milliseconds(int $ms): void
    {
        self::for($ms / 1000);
    }
}
