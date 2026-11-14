<?php

declare(strict_types=1);

namespace Omnitrix\Core\Tests\Unit\Runtime\Exception;

use ErrorException;
use Omnitrix\Core\Log\JsonLogger;
use Omnitrix\Core\Runtime\Exception\ErrorHandler;
use Omnitrix\Core\Runtime\Exception\LoggingExceptionHandler;
use Omnitrix\Core\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

use function error_reporting;
use function fopen;
use function json_decode;
use function rewind;
use function stream_get_contents;
use function trigger_error;

use const E_ALL;
use const E_USER_WARNING;

#[CoversClass(ErrorHandler::class)]
#[CoversClass(LoggingExceptionHandler::class)]
final class ExceptionHandlingTest extends TestCase
{
    public function testErrorHandlerTurnsWarningsIntoExceptions(): void
    {
        $handler = new ErrorHandler();
        $handler->register();
        self::assertTrue($handler->isRegistered());
        // PHPUnit lowers error_reporting() to unhandleable levels during a test; restore the
        // production level so the warning is not treated as suppressed.
        $level = error_reporting(E_ALL);
        try {
            trigger_error('careful', E_USER_WARNING);
            self::fail('warning not converted');
        } catch (ErrorException $e) {
            self::assertSame('careful', $e->getMessage());
            self::assertSame(E_USER_WARNING, $e->getSeverity());
        } finally {
            error_reporting($level);
            $handler->unregister();
        }
        self::assertFalse($handler->isRegistered());
    }

    public function testSuppressedErrorsAreNotConverted(): void
    {
        $handler = new ErrorHandler();
        $handler->register();
        try {
            $result = @trigger_error('quiet', E_USER_WARNING);
            self::assertTrue($result);
        } finally {
            $handler->unregister();
        }
    }

    public function testLoggingHandlerWritesStructuredErrorWithContext(): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        $handler = new LoggingExceptionHandler(new JsonLogger($stream));
        $handler->handle(new RuntimeException('boom', 7), ['request_id' => 'r1', 'password' => 'hunter2']);

        rewind($stream);
        $record = json_decode((string) stream_get_contents($stream), true);
        self::assertIsArray($record);
        self::assertSame('error', $record['level']);
        self::assertSame('boom', $record['message']);
        self::assertSame('r1', $record['request_id']);
        self::assertSame('[redacted]', $record['password']);
        $exception = self::assoc($record['exception']);
        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertSame(7, $exception['code']);
    }

    public function testLoggingHandlerNeverThrowsWhenTheLoggerFails(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<mixed> $context */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('disk full');
            }
        };
        $handler = new LoggingExceptionHandler($logger);
        $this->expectOutputString('');
        $handler->handle(new RuntimeException('original'));
    }
}
