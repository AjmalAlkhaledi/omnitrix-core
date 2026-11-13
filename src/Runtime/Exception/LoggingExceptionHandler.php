<?php

declare(strict_types=1);

namespace Omnitrix\Core\Runtime\Exception;

use Psr\Log\LoggerInterface;
use Throwable;

use function fwrite;

use const PHP_EOL;
use const STDERR;

/**
 * Logs exceptions at `error` level with the originating context. If the logger itself fails,
 * a last-resort line is written to STDERR so the failure is never silent.
 */
final class LoggingExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(Throwable $exception, array $context = []): void
    {
        try {
            $this->logger->error($exception->getMessage(), $context + ['exception' => $exception]);
        } catch (Throwable $loggingFailure) {
            fwrite(STDERR, 'omnitrix: exception handler failed: ' . $loggingFailure->getMessage() . ' while handling ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
        }
    }
}
