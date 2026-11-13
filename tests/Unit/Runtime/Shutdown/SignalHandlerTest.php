<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Shutdown;

use Omnitrix\Core\Runtime\Shutdown\SignalHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Process;
use PHPUnit\Framework\Attributes\CoversClass;

use function getmypid;

#[CoversClass(SignalHandler::class)]
final class SignalHandlerTest extends TestCase
{
    /** SIGUSR2 on Linux; the pcntl constant is avoided because PHPStan's PHP 8.5 pcntl stubs fail to resolve it on this build. */
    private const int SIGUSR2 = 12;

    public function testDeliversSignalsToTheCallbackAndReleases(): void
    {
        $handler = new SignalHandler();
        $received = new Channel(1);
        $handler->onTerminate(static function (int $signal) use ($received): void {
            $received->push($signal);
        }, [self::SIGUSR2]);
        self::assertSame([self::SIGUSR2], $handler->installedSignals());

        Process::kill((int) getmypid(), self::SIGUSR2);
        self::assertSame(self::SIGUSR2, $received->pop(2.0));

        $handler->release();
        self::assertSame([], $handler->installedSignals());
    }
}
